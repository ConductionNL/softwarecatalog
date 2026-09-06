<?php

/**
 * Merge Organisatie Service.
 *
 * Admin-triggered organisation-merge capability (VNG Softwarecatalogus #141):
 * folds a source organisation into a target organisation for gemeentelijke
 * herindeling (municipal mergers) or leveranciersovername (supplier
 * takeovers). Re-points every relation that references the source
 * organisation onto the target, migrates Nextcloud group membership, and
 * soft-retires the source with a tombstone (status = 'merged' +
 * mergedInto) rather than deleting it.
 *
 * `walkRelations()` is the single relation-enumeration routine shared by
 * `dryRun()` (commit: false — enumerate only) and `execute()` (commit: true
 * — enumerate AND re-point), guaranteeing dry-run/execute parity by
 * construction rather than by two independently-maintained implementations.
 *
 * Idempotency/resumability falls out of that same shared walk: once an
 * object has been re-pointed its organisation-reference field(s) no longer
 * equal `sourceUuid`, so a re-run's enumeration naturally finds nothing left
 * to do for that relation type — no separate "completed types" ledger is
 * needed. Every re-point reads the object's full current payload
 * (`ObjectEntity::getObject()`) and mutates only the organisation-reference
 * field(s) before re-saving the complete payload, because OpenRegister's
 * `saveObject()` is PUT-semantic (omitting a field nulls it).
 *
 * Relation-field mapping (design.md "Database Changes"):
 * - usage: `afnemer` (scalar) and `deelnemers` (array) business fields.
 * - contactPerson: `organisatie` (scalar) business field.
 * - aanbod/koppeling: `koppeling.aanbieder` (scalar) business field. The
 *   `aanbod` bucket name is the domain umbrella term (see
 *   AanbodController); `koppeling` is the schema that actually carries an
 *   `aanbieder` ownership field pointing at an organisatie.
 * - contract: the `contract` schema has no business-level organisation
 *   field (no `$ref: organisatie` property), so ownership is carried by
 *   OpenRegister's system-level `@self.organisation` (the same mechanism
 *   design.md documents explicitly for compliancy). Re-pointed via
 *   `@self.organisation` in the save payload, matching OpenRegister's
 *   `SaveObject::setSelfMetadata()` acceptance path, which honours a
 *   caller-supplied `@self.organisation` when the caller is an admin or a
 *   verified member of the target organisation; a merge is admin-triggered, so
 *   the admin arm applies. (An earlier revision of this docblock named
 *   `SaveObject::applyCallerSuppliedFields()`. No such method exists anywhere
 *   in OpenRegister — grepped across the whole tree with a positive control.)
 * - compliancy: `@self.organisation` (system-level owning organisation).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service orchestrating organisation-merge dry-run and execute.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coupling 15 (threshold 13). An organisation
 * merge legitimately touches every system that can hold an organisation reference: OpenRegister
 * objects, Nextcloud groups and users, the app's own organisation handler, the audit log
 * (`CriticalActionPerformedEvent`), the event dispatcher and the progress tracker. The count is
 * the breadth of the merge itself, not an accidental dependency tangle.
 */
class MergeOrganisatieService {
	/**
	 * The tombstone status value written to a merged-away source organisation.
	 *
	 * @var string
	 */
	private const TOMBSTONE_STATUS = 'merged';

	/**
	 * Relation types re-pointed via a business-level object field (scalar and/or array).
	 *
	 * The optional `schema` key overrides the OpenRegister schema slug when it
	 * differs from the relation-type key (`aanbod` lives on the `koppeling`
	 * schema); `walkRelations()` falls back to the key via `?? $type`.
	 *
	 * @var array<string, array{field: string, arrayField: string|null, schema?: string}>
	 */
	private const FIELD_RELATION_TYPES = [
		'usage' => ['field' => 'consumer', 'arrayField' => 'participants'],
		'contactPerson' => ['field' => 'organization', 'arrayField' => null],
		'aanbod' => ['field' => 'provider', 'arrayField' => null, 'schema' => 'connection'],
	];

	/**
	 * Relation types re-pointed via the OpenRegister system-level `@self.organisation` field.
	 *
	 * @var string[]
	 */
	private const SELF_ORGANISATION_RELATION_TYPES = ['catalogContract', 'compliancy'];

	/**
	 * MergeOrganisatieService constructor.
	 *
	 * @param ContainerInterface $container Container interface (lazy OpenRegister service resolution).
	 * @param IAppManager $appManager App manager (checks openregister is installed).
	 * @param IGroupManager $groupManager Group manager (NC group membership migration).
	 * @param LoggerInterface $logger Logger interface (structured audit + diagnostic entries).
	 * @param IEventDispatcher $eventDispatcher Event dispatcher (NC's `CriticalActionPerformedEvent` audit mechanism).
	 * @param SettingsService $settingsService Settings service (register/schema id resolution).
	 * @param OrganisatieService $organisationService Organisatie service (keeps the OR core Organisation.active flag in sync via mapStatus).
	 * @param ProgressTracker $progressTracker Progress tracker (SSE progress-tracking mechanism).
	 * @param OrganizationHandler $organizationHandler Organization handler (NC group creation/lookup for the target org).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly SettingsService $settingsService,
		private readonly OrganisatieService $organisationService,
		private readonly ProgressTracker $progressTracker,
		private readonly OrganizationHandler $organizationHandler,
	) {
	}//end __construct()

	/**
	 * Preview a merge: per-relation-type counts, no writes.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return array{sourceUuid: string, targetUuid: string, counts: array<string, int>, blockers: array<int, array{type: string, message: string}>}
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	public function dryRun(string $sourceUuid, string $targetUuid): array {
		$sourceEntity = $this->findOrganisation(uuid: $sourceUuid);
		$targetEntity = $this->findOrganisation(uuid: $targetUuid);
		$blockers = $this->validateMergeRequest(
			sourceUuid: $sourceUuid,
			targetUuid: $targetUuid,
			sourceEntity: $sourceEntity,
			targetEntity: $targetEntity
		);

		$counts = $this->emptyCounts();
		if (empty($blockers) === true) {
			$counts = $this->walkRelations(sourceUuid: $sourceUuid, targetUuid: $targetUuid, commit: false);
			$counts['groupMembers'] = $this->countGroupMembers(sourceUuid: $sourceUuid);
		}

		$this->auditLog(
			action: 'organisation-merge.dry-run',
			context: [
				'sourceUuid' => $sourceUuid,
				'targetUuid' => $targetUuid,
				'counts' => $counts,
				'blockers' => $blockers,
			]
		);

		return [
			'sourceUuid' => $sourceUuid,
			'targetUuid' => $targetUuid,
			'counts' => $counts,
			'blockers' => $blockers,
		];
	}//end dryRun()

	/**
	 * Execute a merge: re-point every relation type, migrate group
	 * membership, tombstone the source. Idempotent — a re-run against a
	 * partially or fully completed merge only processes what remains (see
	 * class docblock).
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 * @param string|null $actorUid The acting admin's UID (for progress/audit attribution).
	 *
	 * @return array{ok: bool, operationId?: string, sourceUuid: string, targetUuid: string,
	 *   status?: string, counts?: array<string, int>,
	 *   blockers?: array<int, array{type: string, message: string}>}
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function execute(string $sourceUuid, string $targetUuid, ?string $actorUid = null): array {
		$sourceEntity = $this->findOrganisation(uuid: $sourceUuid);
		$targetEntity = $this->findOrganisation(uuid: $targetUuid);
		$blockers = $this->validateMergeRequest(
			sourceUuid: $sourceUuid,
			targetUuid: $targetUuid,
			sourceEntity: $sourceEntity,
			targetEntity: $targetEntity
		);

		if (empty($blockers) === false) {
			$this->auditLog(
				action: 'organisation-merge.execute.blocked',
				context: [
					'sourceUuid' => $sourceUuid,
					'targetUuid' => $targetUuid,
					'blockers' => $blockers,
					'actor' => $actorUid,
				]
			);

			return [
				'ok' => false,
				'sourceUuid' => $sourceUuid,
				'targetUuid' => $targetUuid,
				'blockers' => $blockers,
			];
		}

		$wasAlreadyTombstoned = $sourceEntity !== null
			&& (($sourceEntity->getObject()['status'] ?? null) === self::TOMBSTONE_STATUS);

		$operationId = $this->progressTracker->startOperation(
			operationType: 'org_merge',
			options: [
				'total_items' => count(self::FIELD_RELATION_TYPES) + count(self::SELF_ORGANISATION_RELATION_TYPES),
				'statistics' => [],
			],
			ownerUid: $actorUid
		);

		$this->auditLog(
			action: 'organisation-merge.execute.start',
			context: ['sourceUuid' => $sourceUuid, 'targetUuid' => $targetUuid, 'actor' => $actorUid, 'operationId' => $operationId]
		);

		$counts = $this->walkRelations(sourceUuid: $sourceUuid, targetUuid: $targetUuid, commit: true);
		$counts['groupMembers'] = $this->migrateGroupMembership(sourceUuid: $sourceUuid, targetUuid: $targetUuid);

		$this->tombstoneSource(sourceUuid: $sourceUuid, targetUuid: $targetUuid);

		$this->progressTracker->completeOperation(finalStatistics: ['counts' => $counts]);

		$relationSum = 0;
		foreach (array_merge(array_keys(self::FIELD_RELATION_TYPES), self::SELF_ORGANISATION_RELATION_TYPES) as $type) {
			$relationSum += ($counts[$type] ?? 0);
		}

		$status = 'completed';
		if ($wasAlreadyTombstoned === true && $relationSum === 0) {
			$status = 'already_completed';
		}

		$this->auditLog(
			action: 'organisation-merge.execute.omschrijving',
			context: [
				'sourceUuid' => $sourceUuid,
				'targetUuid' => $targetUuid,
				'actor' => $actorUid,
				'operationId' => $operationId,
				'status' => $status,
				'counts' => $counts,
			]
		);

		return [
			'ok' => true,
			'operationId' => $operationId,
			'sourceUuid' => $sourceUuid,
			'targetUuid' => $targetUuid,
			'status' => $status,
			'counts' => $counts,
		];
	}//end execute()

	/**
	 * The shared relation-enumeration routine. `commit: false` (dry-run)
	 * enumerates and counts without writing; `commit: true` (execute)
	 * enumerates AND re-points. Both callers run the identical per-type
	 * logic below, which is what guarantees dry-run/execute parity.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 * @param bool $commit Whether to write (true) or only count (false).
	 *
	 * @return array<string, int> Per-relation-type counts.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-dry-run-and-execute-must-report-structurally-identical-counts-for-the-same-unchanged-input
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `commit` is the documented dry-run/execute parity gate, not a generic flag.
	 */
	private function walkRelations(string $sourceUuid, string $targetUuid, bool $commit): array {
		$counts = $this->emptyCounts();

		foreach (self::FIELD_RELATION_TYPES as $type => $mapping) {
			$schemaType = $mapping['schema'] ?? $type;
			$counts[$type] = $this->repointByField(
				objectType: $schemaType,
				field: $mapping['field'],
				arrayField: $mapping['arrayField'],
				source: $sourceUuid,
				target: $targetUuid,
				commit: $commit
			);
			$this->reportTypeProgress(type: $type, count: $counts[$type], commit: $commit);
		}

		foreach (self::SELF_ORGANISATION_RELATION_TYPES as $type) {
			$counts[$type] = $this->repointBySelfOrganisation(
				objectType: $type,
				source: $sourceUuid,
				target: $targetUuid,
				commit: $commit
			);
			$this->reportTypeProgress(type: $type, count: $counts[$type], commit: $commit);
		}

		return $counts;
	}//end walkRelations()

	/**
	 * Report per-type progress during execute (no-op during dry-run — a
	 * dry-run does not own a progress-tracking operation).
	 *
	 * @param string $type Relation type identifier.
	 * @param int $count Number of objects processed for this type.
	 * @param bool $commit Whether this is an execute pass (progress is execute-only).
	 *
	 * @return void
	 */
	private function reportTypeProgress(string $type, int $count, bool $commit): void {
		if ($commit === false) {
			return;
		}

		$this->progressTracker->incrementProgress(currentItem: $type, itemType: 'relationType');
		$this->progressTracker->updateStatistics(statistics: [$type => $count]);
	}//end reportTypeProgress()

	/**
	 * Re-point objects of a schema via a business-level scalar field and/or array field.
	 *
	 * @param string $objectType The OpenRegister object type/schema slug.
	 * @param string $field The scalar organisation-reference field name.
	 * @param string|null $arrayField An additional array-of-uuid field name, or null.
	 * @param string $source The source organisation UUID.
	 * @param string $target The target organisation UUID.
	 * @param bool $commit Whether to write (true) or only count (false).
	 *
	 * @return int The number of distinct objects that reference (or referenced) the source.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `commit` is the documented dry-run/execute parity gate.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 10, exactly at the threshold. The
	 * branches are the two reference shapes a schema may use (a scalar organisation field and/or
	 * an array-of-uuid field, either of which may be absent on a given object), crossed with the
	 * dry-run/execute gate above. Splitting the scalar and array paths would duplicate the
	 * count-vs-write logic that must stay identical for dry-run/execute parity to hold.
	 */
	private function repointByField(string $objectType, string $field, ?string $arrayField, string $source, string $target, bool $commit): int {
		$entities = $this->findAllForType(objectType: $objectType);
		$count = 0;

		foreach ($entities as $entity) {
			$data = $entity->getObject();
			$isMatched = false;

			if (($data[$field] ?? null) === $source) {
				$isMatched = true;
				$data[$field] = $target;
			}

			if ($arrayField !== null && is_array($data[$arrayField] ?? null) === true) {
				$arrayMatched = false;
				$newArray = [];
				foreach ($data[$arrayField] as $entry) {
					$replacement = $entry;
					if ($entry === $source) {
						$arrayMatched = true;
						$replacement = $target;
					}

					$newArray[] = $replacement;
				}

				if ($arrayMatched === true) {
					$isMatched = true;
					$data[$arrayField] = $newArray;
				}
			}

			if ($isMatched === false) {
				continue;
			}

			$count++;

			if ($commit === true) {
				$this->saveFull(entity: $entity, data: $data, objectType: $objectType);
			}
		}//end foreach

		return $count;
	}//end repointByField()

	/**
	 * Re-point objects of a schema via the OpenRegister system-level
	 * `@self.organisation` (owning organisation) field.
	 *
	 * @param string $objectType The OpenRegister object type/schema slug.
	 * @param string $source The source organisation UUID.
	 * @param string $target The target organisation UUID.
	 * @param bool $commit Whether to write (true) or only count (false).
	 *
	 * @return int The number of distinct objects that reference (or referenced) the source.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `commit` is the documented dry-run/execute parity gate.
	 */
	private function repointBySelfOrganisation(string $objectType, string $source, string $target, bool $commit): int {
		$entities = $this->findAllForType(objectType: $objectType);
		$count = 0;

		foreach ($entities as $entity) {
			$owningOrganisation = $this->readOwningOrganisation(entity: $entity);

			if ($owningOrganisation !== $source) {
				continue;
			}

			$count++;

			if ($commit === true) {
				$data = $entity->getObject();
				$data['@self'] = ($data['@self'] ?? []) + ['organisation' => $target];
				$this->saveFull(entity: $entity, data: $data, objectType: $objectType);
			}
		}

		return $count;
	}//end repointBySelfOrganisation()

	/**
	 * Read an OpenRegister object's system-level owning organisation
	 * (`@self.organisation`).
	 *
	 * `ObjectEntity` declares `getOrganisation()` ONLY as an `@method` docblock
	 * tag over `protected ?string $organisation`, so the accessor is reached
	 * through `OCP\AppFramework\Db\Entity::__call()`. Two probes are therefore
	 * wrong here, and both fail silently:
	 *
	 * - `method_exists()` is **false** for every such accessor. That was
	 *   stackiq#490: the caller's re-point branch never ran, so a merge
	 *   re-pointed nothing for `contract`/`compliancy` while still tombstoning
	 *   the source organisation.
	 * - `is_callable()` is **true** for ANY name on a class with `__call()`, so
	 *   swapping the probe would make the branch unconditionally true and move
	 *   the failure into a runtime `BadFunctionCallException`.
	 *
	 * `Entity::getter()` itself decides on `property_exists()`, so that is the
	 * primary instrument below; `method_exists()` is kept as a second arm for
	 * an entity that genuinely declares the accessor. The call is still
	 * wrapped, because `$entity` comes from `ObjectService::findAll()` and is
	 * not type-guaranteed to be an `Entity` subclass.
	 *
	 * Deliberately NOT read from `jsonSerialize()`: `ObjectEntity::getObjectArray()`
	 * types `organisation` as `array|string|null`, so an expanded organisation
	 * would silently fail the UUID comparison in the caller. The property holds
	 * the raw `?string`.
	 *
	 * @param object $entity The OpenRegister ObjectEntity to read.
	 *
	 * @return string|null The owning organisation UUID, or null when the entity carries none.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	private function readOwningOrganisation(object $entity): ?string {
		if (property_exists($entity, 'organisation') === false
			&& method_exists($entity, 'getOrganisation') === false
		) {
			return null;
		}

		try {
			$owningOrganisation = $entity->getOrganisation();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MergeOrganisatieService: could not read @self.organisation from object entity',
				['exception' => $e->getMessage(), 'entity' => $entity::class]
			);
			return null;
		}

		if (is_string($owningOrganisation) === false) {
			return null;
		}

		return $owningOrganisation;
	}//end readOwningOrganisation()

	/**
	 * Save the full existing payload (only the organisation-reference field(s)
	 * mutated) back via OpenRegister's `ObjectService::saveObject()` —
	 * PUT-semantic, so the full payload must always be carried forward.
	 *
	 * @param object $entity The existing ObjectEntity (source of the UUID).
	 * @param array $data The full object payload, with only the organisation-reference field(s) changed.
	 * @param string $objectType The OpenRegister object type/schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	private function saveFull(object $entity, array $data, string $objectType): void {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: $objectType);

		if ($objectService === null || $registerId === null || $schemaId === null) {
			$this->logger->error(
				'MergeOrganisatieService: cannot save re-pointed object, register/schema not configured',
				['objectType' => $objectType, 'uuid' => $entity->getUuid()]
			);
			return;
		}

		$objectService->saveObject(
			object: $data,
			extend: [],
			register: (int)$registerId,
			schema: (int)$schemaId,
			uuid: $entity->getUuid()
		);
	}//end saveFull()

	/**
	 * Migrate Nextcloud group membership from the source organisation's
	 * group to the target organisation's group. Idempotent — a user already
	 * in the target group is skipped without error.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return int The number of source-group members processed.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-nc-group-membership-must-be-migrated-from-source-to-target
	 */
	private function migrateGroupMembership(string $sourceUuid, string $targetUuid): int {
		$sourceGroup = $this->resolveSourceGroup(sourceUuid: $sourceUuid);
		if ($sourceGroup === null) {
			return 0;
		}

		$targetGroup = $this->resolveTargetGroup(targetUuid: $targetUuid);
		if ($targetGroup === null) {
			$this->logger->warning(
				'MergeOrganisatieService: could not resolve/create target group for membership migration',
				['targetUuid' => $targetUuid]
			);
			return 0;
		}

		$members = $sourceGroup->getUsers();
		foreach ($members as $user) {
			if ($targetGroup->inGroup($user) === false) {
				$targetGroup->addUser($user);
			}
		}

		return count($members);
	}//end migrateGroupMembership()

	/**
	 * Count the source organisation's NC group members (for dry-run's
	 * `groupMembers` count) without migrating anything.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 *
	 * @return int The number of source-group members.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	private function countGroupMembers(string $sourceUuid): int {
		$sourceGroup = $this->resolveSourceGroup(sourceUuid: $sourceUuid);
		if ($sourceGroup === null) {
			return 0;
		}

		return count($sourceGroup->getUsers());
	}//end countGroupMembers()

	/**
	 * Resolve the source organisation's NC group, if any.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 *
	 * @return \OCP\IGroup|null The source group, or null when unresolvable.
	 */
	private function resolveSourceGroup(string $sourceUuid): ?\OCP\IGroup {
		$sourceEntity = $this->findOrganisation(uuid: $sourceUuid);
		if ($sourceEntity === null) {
			return null;
		}

		$sourceGroupId = $sourceEntity->getObject()['group'] ?? null;
		if (empty($sourceGroupId) === true) {
			return null;
		}

		return $this->groupManager->get($sourceGroupId);
	}//end resolveSourceGroup()

	/**
	 * Resolve (or, via `OrganizationHandler`, create) the target
	 * organisation's NC group.
	 *
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return \OCP\IGroup|null The target group, or null when unresolvable.
	 */
	private function resolveTargetGroup(string $targetUuid): ?\OCP\IGroup {
		$targetEntity = $this->findOrganisation(uuid: $targetUuid);
		if ($targetEntity === null) {
			return null;
		}

		$targetData = $targetEntity->getObject();
		$targetGroupId = $targetData['group'] ?? null;

		if (empty($targetGroupId) === false) {
			$group = $this->groupManager->get($targetGroupId);
			if ($group !== null) {
				return $group;
			}
		}

		$targetGroupId = $this->organizationHandler->ensureOrganizationGroup(organizationObject: $targetEntity, objectData: $targetData);
		if ($targetGroupId === null) {
			return null;
		}

		return $this->groupManager->get($targetGroupId);
	}//end resolveTargetGroup()

	/**
	 * Tombstone the source organisation: PUT-semantic full re-save with
	 * `status = 'merged'` and `mergedInto = targetUuid`, plus keeping
	 * the OR core Organisation.active flag in sync via
	 * `OrganisatieService::updateOrganizationStatus()` (organisatie-service
	 * spec delta).
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
	 */
	private function tombstoneSource(string $sourceUuid, string $targetUuid): void {
		$entity = $this->findOrganisation(uuid: $sourceUuid);
		if ($entity === null) {
			$this->logger->error('MergeOrganisatieService: cannot tombstone, source organisation not found', ['sourceUuid' => $sourceUuid]);
			return;
		}

		$data = $entity->getObject();
		$data['status'] = self::TOMBSTONE_STATUS;
		$data['mergedInto'] = $targetUuid;

		$this->saveFull(entity: $entity, data: $data, objectType: 'organization');

		// Keep the separate OR core Organisation.active flag in sync (organisatie-service spec delta).
		$this->organisationService->updateOrganizationStatus(organizationUuid: $sourceUuid, objectData: ['beoordeling' => self::TOMBSTONE_STATUS]);
	}//end tombstoneSource()

	/**
	 * Validate a merge request, producing a `blockers` array shared by
	 * dry-run and execute so the two paths can never structurally disagree
	 * on whether a merge is legal.
	 *
	 * A source already tombstoned into the SAME requested target is
	 * deliberately NOT a blocker — it is the idempotent re-run case
	 * (execute reports `already_completed`; dry-run reports zero counts).
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 * @param object|null $sourceEntity The resolved source organisation entity, or null.
	 * @param object|null $targetEntity The resolved target organisation entity, or null.
	 *
	 * @return array<int, array{type: string, message: string}> Blockers (empty when the merge may proceed).
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
	 */
	private function validateMergeRequest(string $sourceUuid, string $targetUuid, ?object $sourceEntity, ?object $targetEntity): array {
		$blockers = [];

		if ($sourceUuid === $targetUuid) {
			$blockers[] = ['type' => 'self-merge', 'message' => 'Source and target organisation cannot be the same.'];
			return $blockers;
		}

		if ($sourceEntity === null) {
			$blockers[] = ['type' => 'source-not-found', 'message' => 'Source organisation not found.'];
		}

		if ($targetEntity === null) {
			$blockers[] = ['type' => 'target-not-found', 'message' => 'Target organisation not found.'];
		}

		if ($sourceEntity !== null) {
			$sourceData = $sourceEntity->getObject();
			if (($sourceData['status'] ?? null) === self::TOMBSTONE_STATUS
				&& ($sourceData['mergedInto'] ?? null) !== $targetUuid
			) {
				$blockers[] = [
					'type' => 'source-already-merged',
					'message' => 'Source organisation has already been merged into a different target.',
				];
			}
		}

		if ($targetEntity !== null) {
			$targetData = $targetEntity->getObject();
			if (($targetData['status'] ?? null) === self::TOMBSTONE_STATUS) {
				$blockers[] = [
					'type' => 'target-already-merged',
					'message' => 'Target organisation has already been merged into another organisation.',
				];
			}
		}

		return $blockers;
	}//end validateMergeRequest()

	/**
	 * Find an organisatie object by UUID.
	 *
	 * @param string $uuid The organisation UUID.
	 *
	 * @return object|null The ObjectEntity, or null when not found/unresolvable.
	 */
	private function findOrganisation(string $uuid): ?object {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: 'organization');

		if ($objectService === null || $registerId === null || $schemaId === null) {
			return null;
		}

		try {
			return $objectService->find(id: $uuid, register: (int)$registerId, schema: (int)$schemaId);
		} catch (\Throwable $e) {
			$this->logger->debug('MergeOrganisatieService: organisation not found', ['uuid' => $uuid, 'error' => $e->getMessage()]);
			return null;
		}
	}//end findOrganisatie()

	/**
	 * Find all objects of a given type in the voorzieningen register.
	 *
	 * @param string $objectType The OpenRegister object type/schema slug.
	 *
	 * @return array<int, object> The matching ObjectEntity instances (empty when unresolvable).
	 */
	private function findAllForType(string $objectType): array {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: $objectType);

		if ($objectService === null || $registerId === null || $schemaId === null) {
			return [];
		}

		return $objectService->findAll(
			config: [
				'_register' => (int)$registerId,
				'_schema' => (int)$schemaId,
				'limit' => 10000,
			]
		);
	}//end findAllForType()

	/**
	 * Gets the OpenRegister ObjectService if available.
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null when openregister is not installed.
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error('MergeOrganisatieService: Failed to get ObjectService: ' . $e->getMessage());
			return null;
		}
	}//end getObjectService()

	/**
	 * The empty (all-zero) per-relation-type counts shape.
	 *
	 * @return array<string, int>
	 */
	private function emptyCounts(): array {
		$counts = ['groupMembers' => 0];
		foreach (array_keys(self::FIELD_RELATION_TYPES) as $type) {
			$counts[$type] = 0;
		}

		foreach (self::SELF_ORGANISATION_RELATION_TYPES as $type) {
			$counts[$type] = 0;
		}

		return $counts;
	}//end emptyCounts()

	/**
	 * Write an audit log entry for a dry-run or execute call: a structured
	 * logger entry (queryable/testable) plus Nextcloud's own
	 * `CriticalActionPerformedEvent` (the existing admin_audit mechanism —
	 * no app-local notification/audit dispatch, per ADR-031 precedent).
	 *
	 * @param string $action The audit action identifier.
	 * @param array $context Structured context (actor, source/target uuids, counts, ...).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
	 */
	private function auditLog(string $action, array $context): void {
		$this->logger->info('OrganisationMerge audit: ' . $action, $context + ['audit' => true]);

		$this->eventDispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'OrganisationMerge: %s (source: %s, target: %s)',
				[
					$action,
					$context['sourceUuid'] ?? 'unknown',
					$context['targetUuid'] ?? 'unknown',
				]
			)
		);
	}//end auditLog()
}//end class
