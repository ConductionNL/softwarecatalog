<?php

/**
 * Repair step that backfills the contract approval-projection default.
 *
 * For every existing `contract` object in the `voorzieningen` register that
 * does not yet carry an `approvalState`, this step writes `approvalState =
 * none` so the projection field is present and queryable. It is idempotent
 * (contracts already carrying any `approvalState` are skipped) and fail-safe:
 * it reads real OpenRegister objects (setRegister -> setSchema -> findAll) and
 * never deletes or rewrites any other field. Existing `Actief` contracts are
 * grandfathered — no retroactive decision is raised and `status` is never
 * touched (the `In onderhandeling -> Actief` transition is reserved for an
 * approved decidesk outcome only).
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`); OpenRegister ObjectService calls use the app's established
 * named-argument convention as in MigrateContactsToNc.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Idempotent, fail-safe backfill of contract.approvalState = none.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
class BackfillContractApprovalState implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param SettingsService $settingsService The settings service (register/schema id resolution).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function getName(): string {
		return 'Backfill contract approvalState=none for existing contracts';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function run(IOutput $output): void {
		$output->startProgress(1);

		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister not installed — skipping contract approvalState backfill');
			$output->advance(1);
			$output->finishProgress();
			return;
		}

		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType('catalogContract');
		if ($registerId === null || $schemaId === null) {
			$output->info('Contract register/schema not configured — skipping approvalState backfill');
			$output->advance(1);
			$output->finishProgress();
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister ObjectService unavailable — skipping approvalState backfill');
			$output->advance(1);
			$output->finishProgress();
			return;
		}

		try {
			$contracts = $objectService->setRegister($registerId)
				->setSchema($schemaId)
				->findAll([], false, false);
		} catch (\Throwable $e) {
			$output->warning(sprintf('Could not read contract objects: %s', $e->getMessage()));
			$this->logger->error('[BackfillContractApprovalState] Failed to read contracts', ['error' => $e->getMessage()]);
			$output->advance(1);
			$output->finishProgress();
			return;
		}

		$backfilled = 0;
		foreach ($contracts as $contract) {
			$data = $contract->getObject();

			// Idempotent: any existing approvalState (incl. 'none') → no-op.
			$existing = trim((string)($data['approvalState'] ?? ''));
			if ($existing !== '') {
				continue;
			}

			$data['approvalState'] = ContractApprovalService::APPROVAL_NONE;
			// Status is intentionally never touched — existing Actief
			// contracts are grandfathered, no retroactive decision is raised.
			try {
				$objectService->saveObject(
					object: $data,
					extend: [],
					register: $contract->getRegister(),
					schema: $contract->getSchema(),
					uuid: $contract->getUuid()
				);
				$backfilled++;
			} catch (\Throwable $e) {
				$this->logger->error(
					'[BackfillContractApprovalState] Failed to backfill a contract',
					['uuid' => $contract->getUuid(), 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(sprintf('Contract approvalState backfill: %d contract(s) defaulted to none', $backfilled));
		$output->advance(1);
		$output->finishProgress();
	}//end run()
}//end class
