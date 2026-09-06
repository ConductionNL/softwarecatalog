<?php

/**
 * Contract Status Service.
 *
 * Maintains the lifecycle status of catalog contracts: an `Actief` contract
 * whose `eindDatum` has passed is transitioned to `Verlopen`. The transition
 * is intentionally one-directional and narrow (see shouldExpire()) so a
 * scheduled run can never clobber `In onderhandeling` contracts, contracts
 * without an end date, or any manually-set status in another direction.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side maintenance of contract status (Actief → Verlopen).
 */
class ContractStatusService {
	/**
	 * The active status from which a contract may expire.
	 */
	public const STATUS_ACTIVE = 'Active';

	/**
	 * The expired status a passed-end-date active contract transitions to.
	 */
	public const STATUS_EXPIRED = 'Expired';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param SettingsService $settingsService Resolves register/schema ids.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Pure decision: should this contract transition Actief → Verlopen?
	 *
	 * Returns true ONLY when the status is exactly `Actief` AND a parseable
	 * `eindDatum` is strictly in the past relative to $now. Every other case
	 * (other status, missing/blank/unparseable end date, future end date)
	 * returns false — the transition is never applied.
	 *
	 * @param array $contractData The contract object data bag.
	 * @param DateTimeImmutable $now Logical "now".
	 *
	 * @return bool True when the contract must be expired.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	public function shouldExpire(array $contractData, DateTimeImmutable $now): bool {
		$status = $contractData['status'] ?? null;
		if ($status !== self::STATUS_ACTIVE) {
			return false;
		}

		$endDate = $contractData['endDate'] ?? null;
		if (is_string($endDate) === false || trim($endDate) === '') {
			return false;
		}

		try {
			$end = new DateTimeImmutable($endDate);
		} catch (\Exception $e) {
			// Fail-closed: an unparseable end date never triggers a transition.
			$this->logger->debug(
				'ContractStatusService: unparseable eindDatum, skipping',
				['endDate' => $endDate, 'error' => $e->getMessage()]
			);
			return false;
		}

		return $end < $now;
	}//end shouldExpire()

	/**
	 * Scan all contracts and expire the eligible ones.
	 *
	 * Returns the number of contracts transitioned. Degrades to 0 (no error)
	 * when OpenRegister is unavailable or the contract schema is not configured.
	 *
	 * @param DateTimeImmutable|null $now Logical "now" (defaults to current time).
	 *
	 * @return int The number of contracts transitioned to Verlopen.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	public function expirePastContracts(?DateTimeImmutable $now = null): int {
		$now = ($now ?? new DateTimeImmutable());

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->logger->info('ContractStatusService: ObjectService unavailable, skipping run');
			return 0;
		}

		$registerId = $this->settingsService->getRegisterIdForObjectType('catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType('catalogContract');
		if ($registerId === null || $schemaId === null) {
			$this->logger->info('ContractStatusService: contract register/schema not configured, skipping run');
			return 0;
		}

		// This is a cron-driven scan that structurally needs "all active
		// contracts" (to find every one eligible for expiry) — bounded at a
		// documented safe ceiling rather than left unbounded.
		$query = [
			'register' => $registerId,
			'schema' => $schemaId,
			'status' => self::STATUS_ACTIVE,
			'_limit' => 5000,
		];

		try {
			$contracts = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractStatusService: failed to query active contracts',
				['error' => $e->getMessage()]
			);
			return 0;
		}

		$transitioned = 0;
		foreach ($contracts as $contract) {
			$data = $contract->getObject();
			if ($this->shouldExpire(contractData: $data, now: $now) === false) {
				continue;
			}

			$data['status'] = self::STATUS_EXPIRED;
			try {
				$objectService->saveObject(
					object: $data,
					extend: [],
					register: $contract->getRegister(),
					schema: $contract->getSchema(),
					uuid: $contract->getUuid()
				);
				$transitioned++;
				$this->logger->info(
					'ContractStatusService: contract expired',
					['uuid' => $contract->getUuid(), 'endDate' => $data['endDate'] ?? null]
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'ContractStatusService: failed to expire contract',
					['uuid' => $contract->getUuid(), 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $transitioned;
	}//end expirePastContracts()

	/**
	 * Lazily resolve the OpenRegister ObjectService.
	 *
	 * @return ObjectServiceInterface|null The service, or null when OpenRegister is absent.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			// Ask for the CONTRACT and narrow on the CONTRACT (ADR-084) — see
			// ContractApprovalService::getObjectService() for why gating on the
			// concrete class is a silent fail-closed.
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('ContractStatusService: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}

		return null;
	}//end getObjectService()
}//end class
