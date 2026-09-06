<?php

/**
 * Unit tests for MergeOrganisatieService (organisation-merge).
 *
 * Covers dry-run/execute parity, PUT-semantics field preservation (scalar +
 * array relation fields, and the `@self.organisation` system-level field),
 * idempotent/resumable execute, tombstone-only-after-completion, self-merge
 * and already-tombstoned validation, and NC group membership migration.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for MergeOrganisatieService.
 */
class MergeOrganisatieServiceTest extends TestCase {
	private const REGISTER_ID = 100;

	/**
	 * Object-type => schema id map used by the fixture SettingsService.
	 *
	 * @var array<string, int>
	 */
	private const SCHEMA_IDS = [
		'organization' => 1,
		'usage' => 2,
		'catalogContract' => 3,
		'contactPerson' => 4,
		'connection' => 5,
		'compliancy' => 6,
	];

	/**
	 * Captured saveObject() calls: [{object, register, schema, uuid}].
	 *
	 * @var array<int, array{object: array, register: mixed, schema: mixed, uuid: mixed}>
	 */
	private array $savedCalls = [];

	/**
	 * Reset captured saves between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->savedCalls = [];
	}//end setUp()

	/**
	 * Dry-run reports per-relation-type counts without writing anything.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	public function testDryRunReportsCountsWithoutWriting(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active', 'group' => 'group-a']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active', 'group' => 'group-b']),
			],
			typedFixtures: $this->fullFixtureSet(),
			groupMembers: ['group-a' => ['alice'], 'group-b' => ['carol']]
		);

		$result = $service->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame([], $result['blockers']);
		$this->assertSame(
			[
				'groupMembers' => 1,
				'usage' => 2,
				'contactPerson' => 1,
				'aanbod' => 1,
				'catalogContract' => 1,
				'compliancy' => 1,
			],
			$result['counts']
		);
		$this->assertSame([], $this->savedCalls, 'dry-run MUST NOT write any object');
	}//end testDryRunReportsCountsWithoutWriting()

	/**
	 * Dry-run on an organisation with no relations reports all zeros and no blockers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	public function testDryRunWithNoRelationsReportsAllZeros(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [],
			groupMembers: []
		);

		$result = $service->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame([], $result['blockers']);
		foreach ($result['counts'] as $count) {
			$this->assertSame(0, $count);
		}
	}//end testDryRunWithNoRelationsReportsAllZeros()

	/**
	 * Execute re-points exactly what dry-run counted for the same unchanged input (parity).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-dry-run-and-execute-must-report-structurally-identical-counts-for-the-same-unchanged-input
	 */
	public function testExecuteRepointsExactlyWhatDryRunCounted(): void {
		$organisations = [
			'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active', 'group' => 'group-a']),
			'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active', 'group' => 'group-b']),
		];

		$dryRunService = $this->makeService(
			organisations: $organisations,
			typedFixtures: $this->fullFixtureSet(),
			groupMembers: ['group-a' => ['alice'], 'group-b' => ['carol']]
		);
		$dryRunResult = $dryRunService->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->savedCalls = [];
		$executeService = $this->makeService(
			organisations: $organisations,
			typedFixtures: $this->fullFixtureSet(),
			groupMembers: ['group-a' => ['alice'], 'group-b' => ['carol']]
		);
		$executeResult = $executeService->execute(sourceUuid: 'org-a', targetUuid: 'org-b', actorUid: 'admin1');

		$this->assertTrue($executeResult['ok']);
		$this->assertSame($dryRunResult['counts'], $executeResult['counts']);

		// 2 gebruik + 1 contract + 1 contactpersoon + 1 koppeling + 1 compliancy + 1 tombstone = 7 saves.
		$this->assertCount(7, $this->savedCalls);
	}//end testExecuteRepointsExactlyWhatDryRunCounted()

	/**
	 * An untouched field survives re-pointing (PUT-semantics) — contract via `@self.organisation`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function testUntouchedContractFieldsSurviveRepointing(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [
				'catalogContract' => [
					$this->entity(
						['id' => 'c1', 'contractNumber' => 'C-100', 'cost' => 5000, 'documentReference' => 'doc-ref'],
						uuid: 'c1',
						organisation: 'org-a'
					),
				],
			],
			groupMembers: []
		);

		$service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$contractSave = $this->findSave(schemaId: self::SCHEMA_IDS['catalogContract'], uuid: 'c1');
		$this->assertNotNull($contractSave);
		$this->assertSame('org-b', $contractSave['object']['@self']['organisation']);
		$this->assertSame('C-100', $contractSave['object']['contractNumber']);
		$this->assertSame(5000, $contractSave['object']['cost']);
		$this->assertSame('doc-ref', $contractSave['object']['documentReference']);
	}//end testUntouchedContractFieldsSurviveRepointing()

	/**
	 * The `entity()` double asserts its own premise, against the shape the real
	 * OpenRegister `ObjectEntity` has TODAY.
	 *
	 * When stackiq#490 was written, `getOrganisation()` existed on the
	 * real entity only as an `@method` docblock tag over
	 * `protected ?string $organisation`, i.e. reached through
	 * `Entity::__call()`, so `method_exists()` was FALSE there and TRUE on a
	 * naive double — which is exactly how a `method_exists()` probe stayed
	 * green here while re-pointing nothing in production.
	 *
	 * ADR-084 changed that: `OCA\OpenRegister\Db\ObjectEntity` now implements
	 * `ObjectEntityInterface`, and that interface DECLARES
	 * `getOrganisation(): ?string`, so the real class declares it concretely
	 * (openregister `lib/Db/ObjectEntity.php:833`). An implementor cannot leave
	 * an interface method to `__call()`. This test therefore pins BOTH halves
	 * of the current shape, because `readOwningOrganisation()` still probes the
	 * property first and the accessor second, and either arm going missing puts
	 * the #490 data-loss path back:
	 *
	 *   - the accessor is declared (the published contract requires it), and
	 *   - the backing property is still there, which is what
	 *     `Entity::getter()` — and the service's primary probe — keys on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function testTheEntityDoubleMatchesTheRealObjectEntityAccessorShape(): void {
		$entity = $this->entity(['id' => 'c1'], uuid: 'c1', organisation: 'org-a');

		$this->assertInstanceOf(
			\OCA\OpenRegister\Contract\ObjectEntityInterface::class,
			$entity,
			'the double must satisfy the contract OpenRegister publishes, as the real entity does'
		);
		$this->assertTrue(
			method_exists($entity, 'getOrganisation'),
			'ObjectEntityInterface declares getOrganisation(), so every implementor declares it concretely'
		);
		$this->assertTrue(
			property_exists($entity, 'organisation'),
			'property_exists() is the instrument Entity::getter() — and readOwningOrganisation() — keys on'
		);
		$this->assertSame('org-a', $entity->getOrganisation());
	}//end testTheEntityDoubleMatchesTheRealObjectEntityAccessorShape()

	/**
	 * Objects owned through the system-level `@self.organisation` field
	 * (`contract`, `compliancy`) are re-pointed when the entity reaches
	 * `getOrganisation()` through `Entity::__call()` — which is what every
	 * real OpenRegister `ObjectEntity` does.
	 *
	 * This is the regression test for #490: with a `method_exists()` probe the
	 * branch below never ran, so `execute()` re-pointed NOTHING for these two
	 * relation types while still tombstoning the source organisation — leaving
	 * live objects owned by a retired organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function testSelfOrganisationRelationsAreRepointedForMagicAccessorEntities(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [
				'catalogContract' => [
					$this->entity(
						['id' => 'c1', 'contractNumber' => 'C-100', 'cost' => 5000],
						uuid: 'c1',
						organisation: 'org-a'
					),
					$this->entity(['id' => 'c2'], uuid: 'c2', organisation: 'org-b'),
				],
				'compliancy' => [
					$this->entity(['id' => 'cp1'], uuid: 'cp1', organisation: 'org-a'),
				],
			],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(1, $result['counts']['catalogContract'], 'the contract owned by the source MUST be counted');
		$this->assertSame(1, $result['counts']['compliancy'], 'the compliancy owned by the source MUST be counted');

		$contractSave = $this->findSave(schemaId: self::SCHEMA_IDS['catalogContract'], uuid: 'c1');
		$this->assertNotNull($contractSave, 'the contract MUST be re-pointed, not silently skipped');
		$this->assertSame('org-b', $contractSave['object']['@self']['organisation']);
		// PUT-semantics: every unrelated field is carried forward.
		$this->assertSame('C-100', $contractSave['object']['contractNumber']);
		$this->assertSame(5000, $contractSave['object']['cost']);

		$this->assertNotNull($this->findSave(schemaId: self::SCHEMA_IDS['compliancy'], uuid: 'cp1'));
		$this->assertNull(
			$this->findSave(schemaId: self::SCHEMA_IDS['catalogContract'], uuid: 'c2'),
			'a contract already owned by the target MUST NOT be re-saved'
		);

		// The data-loss half of #490: the source is tombstoned either way, so a
		// silent zero here leaves live objects owned by a retired organisation.
		$tombstone = $this->findSave(schemaId: self::SCHEMA_IDS['organization'], uuid: 'org-a');
		$this->assertNotNull($tombstone);
		$this->assertSame('merged', $tombstone['object']['status']);
	}//end testSelfOrganisationRelationsAreRepointedForMagicAccessorEntities()

	/**
	 * Dry-run and execute agree for magic-accessor entities too. Before #490
	 * they agreed only because BOTH were equally broken (0 == 0), so the
	 * parity assertion could not detect the defect on its own.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-dry-run-and-execute-must-report-structurally-identical-counts-for-the-same-unchanged-input
	 */
	public function testDryRunCountsSelfOrganisationRelationsForMagicAccessorEntities(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [
				'catalogContract' => [$this->entity(['id' => 'c1'], uuid: 'c1', organisation: 'org-a')],
				'compliancy' => [$this->entity(['id' => 'cp1'], uuid: 'cp1', organisation: 'org-a')],
			],
			groupMembers: []
		);

		$result = $service->dryRun(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(1, $result['counts']['catalogContract']);
		$this->assertSame(1, $result['counts']['compliancy']);
		$this->assertSame([], $this->savedCalls, 'dry-run MUST NOT write any object');
	}//end testDryRunCountsSelfOrganisationRelationsForMagicAccessorEntities()

	/**
	 * A gebruik object with the source as one of several deelnemers only
	 * replaces the matching entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function testGebruikDeelnemersOnlyReplacesMatchingEntry(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [
				'usage' => [
					$this->entity(
						['id' => 'g1', 'consumer' => 'org-x', 'participants' => ['org-a', 'org-c', 'org-d']],
						uuid: 'g1'
					),
				],
			],
			groupMembers: []
		);

		$service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$save = $this->findSave(schemaId: self::SCHEMA_IDS['usage'], uuid: 'g1');
		$this->assertNotNull($save);
		$this->assertSame(['org-b', 'org-c', 'org-d'], $save['object']['participants']);
		$this->assertSame('org-x', $save['object']['consumer'], 'afnemer was already not the source — must stay untouched');
	}//end testGebruikDeelnemersOnlyReplacesMatchingEntry()

	/**
	 * Re-running execute after gebruik/contract already completed does not
	 * re-point them a second time, and processes the remaining types.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type
	 */
	public function testReRunningExecuteAfterPartialCompletionOnlyFinishesRemainingTypes(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [
				// gebruik/contract already point at the target — nothing left to do.
				'usage' => [$this->entity(['id' => 'g1', 'consumer' => 'org-b'], uuid: 'g1')],
				'catalogContract' => [$this->entity(['id' => 'c1'], uuid: 'c1', organisation: 'org-b')],
				// contactpersoon/koppeling/compliancy still reference the source.
				'contactPerson' => [$this->entity(['id' => 'p1', 'organization' => 'org-a'], uuid: 'p1')],
				'connection' => [$this->entity(['id' => 'k1', 'provider' => 'org-a'], uuid: 'k1')],
				'compliancy' => [$this->entity(['id' => 'cp1'], uuid: 'cp1', organisation: 'org-a')],
			],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(0, $result['counts']['usage']);
		$this->assertSame(0, $result['counts']['catalogContract']);
		$this->assertSame(1, $result['counts']['contactPerson']);
		$this->assertSame(1, $result['counts']['aanbod']);
		$this->assertSame(1, $result['counts']['compliancy']);

		$this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['usage'], uuid: 'g1'));
		$this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['catalogContract'], uuid: 'c1'));
		$this->assertNotNull($this->findSave(schemaId: self::SCHEMA_IDS['contactPerson'], uuid: 'p1'));
	}//end testReRunningExecuteAfterPartialCompletionOnlyFinishesRemainingTypes()

	/**
	 * Re-running a fully completed merge (source already tombstoned into the
	 * SAME target) is a safe no-op: no relation object is modified and the
	 * response reports `already_completed`, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type
	 */
	public function testReRunningAFullyCompletedMergeIsASafeNoOp(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'merged', 'mergedInto' => 'org-b']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [
				// Everything already re-pointed to the target.
				'usage' => [$this->entity(['id' => 'g1', 'consumer' => 'org-b'], uuid: 'g1')],
				'catalogContract' => [$this->entity(['id' => 'c1'], uuid: 'c1', organisation: 'org-b')],
			],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertTrue($result['ok']);
		$this->assertSame('already_completed', $result['status']);
		$this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['usage'], uuid: 'g1'));
		$this->assertNull($this->findSave(schemaId: self::SCHEMA_IDS['catalogContract'], uuid: 'c1'));
	}//end testReRunningAFullyCompletedMergeIsASafeNoOp()

	/**
	 * The source organisation is tombstoned (status + mergedInto) only once
	 * execute completes, is never deleted, and every other pre-existing
	 * field on it is unchanged (PUT-semantics on the organisatie object itself).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
	 */
	public function testSourceOrganisationIsTombstonedAfterSuccessfulMerge(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active', 'name' => 'Gemeente A', 'type' => 'Municipality']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active']),
			],
			typedFixtures: [],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame('completed', $result['status']);

		$tombstoneSave = $this->findSave(schemaId: self::SCHEMA_IDS['organization'], uuid: 'org-a');
		$this->assertNotNull($tombstoneSave);
		$this->assertSame('merged', $tombstoneSave['object']['status']);
		$this->assertSame('org-b', $tombstoneSave['object']['mergedInto']);
		// Pre-existing fields survive (PUT-semantic full re-save).
		$this->assertSame('Gemeente A', $tombstoneSave['object']['name']);
		$this->assertSame('Municipality', $tombstoneSave['object']['type']);
	}//end testSourceOrganisationIsTombstonedAfterSuccessfulMerge()

	/**
	 * NC group membership is migrated from source to target; pre-existing
	 * target membership does not error.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-nc-group-membership-must-be-migrated-from-source-to-target
	 */
	public function testGroupMembershipIsMigratedFromSourceToTarget(): void {
		$addedUsers = [];

		$sourceGroup = $this->createMock(IGroup::class);
		$sourceGroup->method('getUsers')->willReturn([$this->user('alice'), $this->user('bob')]);

		$targetGroup = $this->createMock(IGroup::class);
		$targetGroup->method('inGroup')->willReturnCallback(
			static fn (IUser $user) => $user->getUID() === 'carol'
		);
		$targetGroup->method('addUser')->willReturnCallback(
			function (IUser $user) use (&$addedUsers) {
				$addedUsers[] = $user->getUID();
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			static function (string $gid) use ($sourceGroup, $targetGroup) {
				return match ($gid) {
					'group-a' => $sourceGroup,
					'group-b' => $targetGroup,
					default => null,
				};
			}
		);

		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active', 'group' => 'group-a']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'Active', 'group' => 'group-b']),
			],
			typedFixtures: [],
			groupMembers: [],
			groupManagerOverride: $groupManager
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(2, $result['counts']['groupMembers']);
		$this->assertSame(['alice', 'bob'], $addedUsers, 'both source members are added — carol overlap causes no error');
	}//end testGroupMembershipIsMigratedFromSourceToTarget()

	/**
	 * Self-merge is rejected with no writes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
	 */
	public function testSelfMergeIsRejected(): void {
		$service = $this->makeService(
			organisations: ['org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active'])],
			typedFixtures: [],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-a');

		$this->assertFalse($result['ok']);
		$this->assertSame('self-merge', $result['blockers'][0]['type']);
		$this->assertSame([], $this->savedCalls);
	}//end testSelfMergeIsRejected()

	/**
	 * Merging into an already-tombstoned target is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
	 */
	public function testMergingIntoAnAlreadyTombstonedTargetIsRejected(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'Active']),
				'org-b' => $this->entity(['id' => 'org-b', 'status' => 'merged', 'mergedInto' => 'org-z']),
			],
			typedFixtures: [],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-b');

		$this->assertFalse($result['ok']);
		$this->assertSame('target-already-merged', $result['blockers'][0]['type']);
		$this->assertSame([], $this->savedCalls);
	}//end testMergingIntoAnAlreadyTombstonedTargetIsRejected()

	/**
	 * Re-merging an already-tombstoned source into a DIFFERENT target is
	 * rejected as a validation error (not a silent success); the source's
	 * existing `mergedInto` is left untouched (no write occurs at all).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
	 */
	public function testReMergingAnAlreadyTombstonedSourceIntoADifferentTargetIsRejected(): void {
		$service = $this->makeService(
			organisations: [
				'org-a' => $this->entity(['id' => 'org-a', 'status' => 'merged', 'mergedInto' => 'org-b']),
				'org-c' => $this->entity(['id' => 'org-c', 'status' => 'Active']),
			],
			typedFixtures: [],
			groupMembers: []
		);

		$result = $service->execute(sourceUuid: 'org-a', targetUuid: 'org-c');

		$this->assertFalse($result['ok']);
		$this->assertSame('source-already-merged', $result['blockers'][0]['type']);
		$this->assertSame([], $this->savedCalls);
	}//end testReMergingAnAlreadyTombstonedSourceIntoADifferentTargetIsRejected()

	/**
	 * Build the full 5-type + group-member fixture set used by the parity tests.
	 *
	 * @return array<string, array<int, ObjectEntity>>
	 */
	private function fullFixtureSet(): array {
		return [
			'usage' => [
				$this->entity(['id' => 'g1', 'consumer' => 'org-a', 'participants' => ['org-c']], uuid: 'g1'),
				$this->entity(['id' => 'g2', 'consumer' => 'org-x', 'participants' => ['org-a', 'org-c', 'org-d']], uuid: 'g2'),
				$this->entity(['id' => 'g3', 'consumer' => 'org-y', 'participants' => ['org-z']], uuid: 'g3'),
			],
			'catalogContract' => [
				$this->entity(['id' => 'c1', 'contractNumber' => 'C-100'], uuid: 'c1', organisation: 'org-a'),
				$this->entity(['id' => 'c2'], uuid: 'c2', organisation: 'org-b'),
			],
			'contactPerson' => [
				$this->entity(['id' => 'p1', 'organization' => 'org-a'], uuid: 'p1'),
				$this->entity(['id' => 'p2', 'organization' => 'org-b'], uuid: 'p2'),
			],
			'connection' => [
				$this->entity(['id' => 'k1', 'provider' => 'org-a'], uuid: 'k1'),
				$this->entity(['id' => 'k2', 'provider' => 'org-b'], uuid: 'k2'),
			],
			'compliancy' => [
				$this->entity(['id' => 'cp1'], uuid: 'cp1', organisation: 'org-a'),
			],
		];
	}//end fullFixtureSet()

	/**
	 * Find a captured saveObject() call for a given schema id + uuid.
	 *
	 * @param int $schemaId The schema id.
	 * @param string $uuid The object uuid.
	 *
	 * @return array{object: array, register: mixed, schema: mixed, uuid: mixed}|null
	 */
	private function findSave(int $schemaId, string $uuid): ?array {
		foreach ($this->savedCalls as $call) {
			if ((int)$call['schema'] === $schemaId && $call['uuid'] === $uuid) {
				return $call;
			}
		}

		return null;
	}//end findSave()

	/**
	 * Build a fully-wired MergeOrganisatieService with fixture-backed collaborators.
	 *
	 * @param array<string, ObjectEntity> $organisations Organisatie fixtures keyed by uuid (find()).
	 * @param array<string, array<int, ObjectEntity>> $typedFixtures Non-organisatie fixtures keyed by object type (findAll()).
	 * @param array<string, array<int, string>> $groupMembers Group id => member usernames (used unless groupManagerOverride is given).
	 * @param IGroupManager|null $groupManagerOverride Explicit IGroupManager mock (overrides $groupMembers).
	 *
	 * @return MergeOrganisatieService
	 */
	private function makeService(
		array $organisations,
		array $typedFixtures,
		array $groupMembers,
		?IGroupManager $groupManagerOverride = null,
	): MergeOrganisatieService {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('find')->willReturnCallback(
			function (string|int $id) use ($organisations) {
				return $organisations[$id] ?? null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($typedFixtures) {
				$schemaId = (int)($config['_schema'] ?? 0);
				foreach (self::SCHEMA_IDS as $type => $id) {
					if ($id === $schemaId && isset($typedFixtures[$type]) === true) {
						return $typedFixtures[$type];
					}
				}

				return [];
			}
		);

		// The callback's parameter LIST must mirror
		// `ObjectServiceInterface::saveObject()` position for position. PHPUnit
		// resolves the subject's named arguments against the generated mock's own
		// signature and then invokes this callback POSITIONALLY, so a callback
		// that omits `$extend` — the contract's second parameter — silently
		// receives register in `$schema` and schema in `$uuid`. Nothing throws:
		// the capture just records the wrong coordinates, and every assertion
		// that looks a save up by (schema, uuid) reports "no such save".
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array|ObjectEntity $object,
				?array $extend = [],
				$register = null,
				$schema = null,
				$uuid = null,
			) {
				$data = ($object instanceof ObjectEntity) === true ? $object->getObject() : $object;
				$this->savedCalls[] = [
					'object' => $data,
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
				];
				return $this->createStub(ObjectEntity::class);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not bound: ' . $id);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenRegisterId')->willReturn(self::REGISTER_ID);
		$settingsService->method('getSchemaIdForObjectType')->willReturnCallback(
			static function (string $objectType) {
				return self::SCHEMA_IDS[$objectType] ?? null;
			}
		);

		$groupManager = $groupManagerOverride;
		if ($groupManager === null) {
			$groupManager = $this->createMock(IGroupManager::class);
			$groupManager->method('get')->willReturnCallback(
				function (string $gid) use ($groupMembers) {
					if (isset($groupMembers[$gid]) === false) {
						return null;
					}

					$group = $this->createMock(IGroup::class);
					$members = array_map(fn (string $uid) => $this->user($uid), $groupMembers[$gid]);
					$group->method('getUsers')->willReturn($members);
					$group->method('inGroup')->willReturn(false);

					return $group;
				}
			);
		}

		$organisationService = $this->createMock(OrganisatieService::class);
		$progressTracker = $this->createMock(ProgressTracker::class);
		$progressTracker->method('startOperation')->willReturn('op-1');
		$organizationHandler = $this->createMock(OrganizationHandler::class);

		return new MergeOrganisatieService(
			container: $container,
			appManager: $appManager,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			settingsService: $settingsService,
			organisationService: $organisationService,
			progressTracker: $progressTracker,
			organizationHandler: $organizationHandler
		);
	}//end makeService()

	/**
	 * Build a FAITHFUL OpenRegister ObjectEntity double: a concrete subclass of
	 * the `ObjectEntity` stub, whose `organisation` and `uuid` attributes are
	 * reached through the stub's `__call()` — which mirrors
	 * `OCP\AppFramework\Db\Entity::__call()`, exactly as the real
	 * `ObjectEntity` reaches them.
	 *
	 * This used to return `createMock(ObjectEntity::class)` over a stub that
	 * declared `getOrganisation()` CONCRETELY — PHPUnit 10 removed
	 * `addMethods()`, so a mock cannot configure a magic accessor. That double
	 * made `method_exists($entity, 'getOrganisation')` TRUE in the suite and
	 * FALSE in production, i.e. it inverted the exact predicate under test, and
	 * is why stackiq#490 was green here for its entire life.
	 *
	 * It must be a SUBCLASS, not an arbitrary `Entity`: `ObjectService::find()`
	 * declares `?ObjectEntity`, and an incompatible return raises a `TypeError`
	 * that `findOrganisatie()` swallows into a `source-not-found` blocker.
	 *
	 * @param array<string, mixed> $data The object payload (getObject()).
	 * @param string|null $uuid The uuid (defaults to $data['id']).
	 * @param string|null $organisation The system-level `@self.organisation` owner.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, ?string $uuid = null, ?string $organisation = null): ObjectEntity {
		$entity = new class extends ObjectEntity {

			/**
			 * The object uuid — a property, reached via __call as on the real entity.
			 *
			 * @var string|null
			 */
			protected ?string $uuid = null;

			/**
			 * The object payload.
			 *
			 * @var array<string, mixed>|null
			 */
			protected ?array $object = null;

			/**
			 * The numeric database id.
			 *
			 * @return int
			 */
			public function getId() {
				return 0;
			}//end getId()

			/**
			 * The object uuid.
			 *
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return (string)$this->uuid;
			}//end getUuid()

			/**
			 * Mirrors ObjectEntity::getObject(), which is explicitly declared
			 * (not magic) on the real entity because it injects the uuid as `id`.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return array_merge(['id' => $this->uuid], ($this->object ?? []));
			}//end getObject()

			/**
			 * The register id — unused by these tests.
			 *
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return null;
			}//end getRegister()

			/**
			 * The schema id — unused by these tests.
			 *
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return null;
			}//end getSchema()

			/**
			 * Set the object payload.
			 *
			 * @param array<string, mixed>|null $object The payload.
			 *
			 * @return self
			 */
			public function setObject($object = null) {
				$this->object = $object;
				return $this;
			}//end setObject()

			/**
			 * Serialise the payload.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize() {
				return $this->getObject();
			}//end jsonSerialize()
		};

		$entity->setUuid($uuid ?? (string)($data['id'] ?? ''));
		$entity->setObject($data);
		$entity->setOrganisation($organisation);

		return $entity;
	}//end entity()

	/**
	 * Build an IUser mock with the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()
}//end class
