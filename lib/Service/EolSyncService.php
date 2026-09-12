<?php

/**
 * EOL Sync Service.
 *
 * Orchestrates the `eol-feed-integration` matcher: resolves the configured
 * `eol_product`/`eol_cycle` register/schema (provisioned by the sibling
 * openconnector `endoflife-date-source` change), reads only via
 * OpenRegister's `ObjectService` — never HTTP — finds every catalog module
 * with an `eolProductSlug` mapping, delegates the actual matching/stamping
 * decision to `EolMatcherService`, and reports a status summary. Degrades
 * gracefully (no exception surfaced, no object modified) whenever the feed
 * is disabled or the configured register/schema cannot be resolved — the
 * same entry point serves both the scheduled background job and the manual
 * "sync now" admin endpoint, so the two trigger paths can never drift.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-softwarecatalog-performs-no-direct-http-to-the-eol-feed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates config resolution, mapped-module discovery, and delegated
 * matching/stamping for the EOL feed integration.
 */
class EolSyncService {

	/**
	 * The provenance source identifier stamped on every matched field.
	 *
	 * @var string
	 */
	private const SOURCE = 'endoflife.date';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (config/status/register-schema resolution).
	 * @param EolMatcherService $matcher The pure matching/stamping logic.
	 * @param ITimeFactory $timeFactory The time factory (sync-run timestamp).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EolMatcherService $matcher,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the current EOL sync configuration.
	 *
	 * @return array The configuration (see `SettingsService::getEolSyncConfig()`).
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function getConfig(): array {
		return $this->settingsService->getEolSyncConfig();
	}//end getConfig()

	/**
	 * Update the EOL sync configuration.
	 *
	 * @param array $data The submitted configuration fields.
	 *
	 * @return array The persisted configuration result.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function updateConfig(array $data): array {
		return $this->settingsService->updateEolSyncConfig($data);
	}//end updateConfig()

	/**
	 * Get the last-recorded sync status.
	 *
	 * @return array The status (see `SettingsService::getEolSyncStatus()`).
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function getStatus(): array {
		return $this->settingsService->getEolSyncStatus();
	}//end getStatus()

	/**
	 * Run the EOL sync: resolve config and the EOL register/schema, find
	 * every mapped module, match and stamp its versions, and record the
	 * outcome. Invoked identically by the scheduled background job and the
	 * manual "sync now" admin endpoint.
	 *
	 * Never throws to the caller — every failure mode (feature disabled,
	 * OpenRegister absent, register/schema not resolvable) degrades to a
	 * recorded "unavailable" status instead, per the graceful-degradation
	 * requirement.
	 *
	 * @return array{available: bool, reason: string|null, matched: int, skipped: int, lastRunAt: string|null}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-softwarecatalog-performs-no-direct-http-to-the-eol-feed
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 13 (threshold 10). The spec
	 * requires this method to never throw: feature disabled, OpenRegister absent, register or
	 * schema unresolvable, and per-module match failures each have to degrade to a recorded
	 * "unavailable"/skipped outcome rather than propagate. Each of those is one flat guard.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) NPath 896 (threshold 200) — the combinatorial
	 * product of those independent graceful-degradation guards, not nested logic.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 100 lines, exactly at the threshold. The
	 * resolve-config → resolve-register/schema → match → stamp → record-status sequence is one
	 * transaction whose steps all feed the single status array returned at the end; extracting
	 * the middle steps would mean threading that accumulator through helpers for no real gain.
	 */
	public function run(): array {
		$config = $this->settingsService->getEolSyncConfig();

		if ($config['enabled'] !== true) {
			return $this->degrade(reason: 'disabled');
		}

		if ($this->settingsService->isOpenRegisterInstalled() === false) {
			return $this->degrade(reason: 'openregister-not-installed');
		}

		try {
			$objectService = $this->settingsService->getObjectService();
		} catch (\Throwable $e) {
			$this->logger->info(
				'[EolSyncService] OpenRegister ObjectService unavailable — degrading to manual-only',
				['error' => $e->getMessage()]
			);
			return $this->degrade(reason: 'object-service-unavailable');
		}

		if ($objectService === null) {
			return $this->degrade(reason: 'object-service-unavailable');
		}

		$moduleRegisterId = $this->settingsService->getRegisterIdForObjectType('module');
		$moduleSchemaId = $this->settingsService->getSchemaIdForObjectType('module');
		$versionSchemaId = $this->settingsService->getSchemaIdForObjectType('moduleVersion');

		if ($moduleRegisterId === null || $moduleSchemaId === null || $versionSchemaId === null) {
			return $this->degrade(reason: 'module-schema-not-configured');
		}

		if ($this->resolveEolContext(objectService: $objectService, config: $config) === false) {
			return $this->degrade(reason: 'eol-register-or-schema-not-found');
		}

		$mappedModules = $this->findMappedModules(
			objectService: $objectService,
			moduleRegisterId: $moduleRegisterId,
			moduleSchemaId: $moduleSchemaId
		);

		$fetchedAt = $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM);

		$totalStamped = 0;
		$totalSkipped = 0;

		foreach ($mappedModules as $module) {
			$slug = trim((string)($module['eolProductSlug'] ?? ''));
			$moduleUuid = (string)($module['id'] ?? '');
			if ($slug === '' || $moduleUuid === '') {
				continue;
			}

			$cycles = $this->fetchCycles(
				objectService: $objectService,
				config: $config,
				productSlug: $slug
			);

			$moduleVersions = $this->fetchModuleVersions(
				objectService: $objectService,
				moduleRegisterId: $moduleRegisterId,
				versionSchemaId: $versionSchemaId,
				moduleUuid: $moduleUuid
			);

			$result = $this->matcher->matchModuleVersions(
				moduleVersions: $moduleVersions,
				cycles: $cycles,
				source: self::SOURCE,
				fetchedAt: $fetchedAt
			);

			foreach ($result['stamped'] as $stampedVersion) {
				$this->saveStampedVersion(
					objectService: $objectService,
					moduleRegisterId: $moduleRegisterId,
					versionSchemaId: $versionSchemaId,
					stampedVersion: $stampedVersion
				);
				$totalStamped++;
			}

			$totalSkipped += count($result['skipped']);
		}//end foreach

		$status = [
			'available' => true,
			'reason' => null,
			'matched' => $totalStamped,
			'skipped' => $totalSkipped,
			'lastRunAt' => $fetchedAt,
		];
		$this->settingsService->setEolSyncStatus($status);

		return $status;
	}//end run()

	/**
	 * Resolve the configured EOL register + `eol_product`/`eol_cycle` schemas
	 * on the given `ObjectService` context. Returns false (never throws)
	 * when the register or either schema cannot be found.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array $config The EOL sync configuration.
	 *
	 * @return bool True when the register/schemas resolved successfully.
	 */
	private function resolveEolContext(ObjectServiceInterface $objectService, array $config): bool {
		try {
			$objectService->setRegister($config['register']);
			$objectService->setSchema($config['productSchema']);
			$objectService->setSchema($config['cycleSchema']);
		} catch (\Throwable $e) {
			$this->logger->info(
				'[EolSyncService] EOL register/schema not resolvable — degrading to manual-only',
				[
					'register' => $config['register'],
					'productSchema' => $config['productSchema'],
					'cycleSchema' => $config['cycleSchema'],
					'error' => $e->getMessage(),
				]
			);
			return false;
		}

		return true;
	}//end resolveEolContext()

	/**
	 * Find every module with a non-empty `eolProductSlug` mapping.
	 *
	 * Modules without the mapping are never read individually again after
	 * this listing pass — the per-module `eol_cycle` read and `moduleVersie`
	 * write only happen for mapped modules (design.md non-functional
	 * performance note).
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param int $moduleRegisterId The module register id.
	 * @param int $moduleSchemaId The module schema id.
	 *
	 * @return array The mapped module rows (normalised arrays).
	 */
	private function findMappedModules(ObjectServiceInterface $objectService, int $moduleRegisterId, int $moduleSchemaId): array {
		$query = [
			'@self' => [
				'schema' => $moduleSchemaId,
				'register' => $moduleRegisterId,
			],
			'_limit' => 1000,
		];

		$modules = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		if (is_array($modules) === false) {
			return [];
		}

		$mapped = [];
		foreach ($modules as $module) {
			$data = $this->normalizeRow(row: $module);
			if (trim((string)($data['eolProductSlug'] ?? '')) !== '') {
				$mapped[] = $data;
			}
		}

		return $mapped;
	}//end findMappedModules()

	/**
	 * Fetch the `eol_cycle` rows for one product slug, scoped to the
	 * configured EOL register/schema. Defensively re-filters on `product`
	 * in PHP in case the underlying query filter is looser than an exact
	 * match — matching must never cross into another product's cycles
	 * (design.md Decision 2 mitigation).
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array $config The EOL sync configuration.
	 * @param string $productSlug The mapped module's `eolProductSlug`.
	 *
	 * @return array The matching `eol_cycle` rows (normalised arrays).
	 */
	private function fetchCycles(ObjectServiceInterface $objectService, array $config, string $productSlug): array {
		try {
			$objectService->setRegister($config['register']);
			$objectService->setSchema($config['cycleSchema']);
			$rows = $objectService->findAll(
				config: [
					'filters' => ['product' => $productSlug],
					'limit' => 500,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[EolSyncService] Failed to read eol_cycle rows for product — module skipped this run',
				['product' => $productSlug, 'error' => $e->getMessage()]
			);
			return [];
		}

		// No `is_array($rows)` guard: `ObjectServiceInterface::findAll()` declares
		// `array`, and the only non-array outcome — a throw — is already handled
		// above. The check could never be true.
		$cycles = [];
		foreach ($rows as $row) {
			$data = $this->normalizeRow(row: $row);
			if ((string)($data['product'] ?? '') === $productSlug) {
				$cycles[] = $data;
			}
		}

		return $cycles;
	}//end fetchCycles()

	/**
	 * Fetch the `moduleVersie` rows belonging to one module.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param int $moduleRegisterId The (stackiq) module register id.
	 * @param int $versionSchemaId The moduleVersie schema id.
	 * @param string $moduleUuid The owning module's uuid.
	 *
	 * @return array The module's `moduleVersie` rows (normalised arrays).
	 */
	private function fetchModuleVersions(
		ObjectServiceInterface $objectService,
		int $moduleRegisterId,
		int $versionSchemaId,
		string $moduleUuid,
	): array {
		$query = [
			'@self' => [
				'schema' => $versionSchemaId,
				'register' => $moduleRegisterId,
			],
			'module' => $moduleUuid,
			'_limit' => 200,
		];

		try {
			$rows = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[EolSyncService] Failed to read moduleVersie rows for module — module skipped this run',
				['module' => $moduleUuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_map(fn ($row) => $this->normalizeRow(row: $row), $rows);
	}//end fetchModuleVersions()

	/**
	 * Save a stamped `moduleVersie` — PUT-semantic: the complete object
	 * (every original field plus the stamp) is passed through, and the
	 * existing uuid is supplied so this is an update, never a duplicate
	 * create.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param int $moduleRegisterId The module register id.
	 * @param int $versionSchemaId The moduleVersie schema id.
	 * @param array $stampedVersion The complete stamped `moduleVersie` object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-stamping-preserves-every-other-field-and-records-provenance
	 */
	private function saveStampedVersion(
		ObjectServiceInterface $objectService,
		int $moduleRegisterId,
		int $versionSchemaId,
		array $stampedVersion,
	): void {
		$uuid = (string)($stampedVersion['id'] ?? '');
		if ($uuid === '') {
			$this->logger->warning('[EolSyncService] Stamped moduleVersie has no uuid — skipping save');
			return;
		}

		try {
			$objectService->saveObject(
				object: $stampedVersion,
				register: $moduleRegisterId,
				schema: $versionSchemaId,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[EolSyncService] Failed to save a stamped moduleVersie — left untouched',
				['uuid' => $uuid, 'error' => $e->getMessage()]
			);
		}
	}//end saveStampedVersion()

	/**
	 * Normalise an OpenRegister search/list result row to a plain array,
	 * regardless of whether the caller returned a rendered array or an
	 * entity object (mirrors the defensive shape-handling already used by
	 * `OrganizationContactSyncJob::refreshOne()`).
	 *
	 * @param mixed $row The raw row.
	 *
	 * @return array The normalised row, always carrying an `id` key when resolvable.
	 */
	private function normalizeRow(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$data = (array)$row->getObject();
			if (method_exists($row, 'getUuid') === true && empty($data['id']) === true) {
				$data['id'] = (string)$row->getUuid();
			}

			return $data;
		}

		return (array)$row;
	}//end normalizeRow()

	/**
	 * Record and return a graceful "unavailable" status.
	 *
	 * @param string $reason The unavailability reason code.
	 *
	 * @return array{available: bool, reason: string|null, matched: int, skipped: int, lastRunAt: string|null}
	 */
	private function degrade(string $reason): array {
		$status = [
			'available' => false,
			'reason' => $reason,
			'matched' => 0,
			'skipped' => 0,
			'lastRunAt' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM),
		];
		$this->settingsService->setEolSyncStatus($status);

		return $status;
	}//end degrade()
}//end class
