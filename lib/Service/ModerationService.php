<?php

/**
 * Registration / review moderation / approval queue.
 *
 * The admin-only counterpart to IntakeService (organisatie) and
 * ReviewService (beoordeeling) — a single generalised mechanism, not two
 * parallel ones (catalog-ratings, stackiq#375: "reuse the
 * ModerationQueue.vue pattern... do not invent a second moderation
 * mechanism"). Every method takes an explicit `$type` selecting which
 * moderated type/field/values to operate on; it defaults to the original
 * `organisatie` behavior so every pre-existing caller (and
 * `IntakeModerationTest.php`'s assertions, none of which pass a `$type`) is
 * unaffected byte-for-byte.
 *
 * `organisatie` (default): field `registratiestatus`, decided into
 * `active`/`rejected`; approval ALSO stamps `publicatiedatum = now` so the
 * open-data public RBAC gate (`publicatiedatum<=$now`) makes the entry
 * anonymously visible — the exact same gate as open-data publish.
 *
 * `beoordeeling`: field `status`, decided into `approved`/`rejected`; no
 * `publicatiedatum` involved — the schema's own `status: approved`-matched
 * public RBAC rule (register.d/catalog-ratings.json) does the same job for
 * reviews that `publicatiedatum` does for organisaties.
 *
 * All writes go through the OpenRegister ObjectService (ADR-022). The service
 * itself performs no auth — it is only ever reachable from the admin-gated
 * ModerationController.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lists + decides the organisatie / beoordeeling moderation queues.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ModerationService {
	/**
	 * The moderated catalog object type (default / legacy — organisatie).
	 */
	public const MODERATED_TYPE = 'organization';

	/**
	 * The review moderated catalog object type.
	 */
	public const MODERATED_TYPE_REVIEW = 'software-review';

	/**
	 * Pending (awaiting moderation) state — shared field value across types.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Approved (active, publishable) state for organisatie.
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Approved (publicly visible) state for beoordeeling.
	 */
	public const STATUS_APPROVED = 'approved';

	/**
	 * Rejected state — shared field value across types.
	 */
	public const STATUS_REJECTED = 'rejected';

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
	 * List the pending entries (of the given type) awaiting moderation.
	 *
	 * @param string $type The moderated object type (default: organisatie).
	 *
	 * @return array{ok:bool, reason:string, items:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
	 */
	public function listPending(string $type = self::MODERATED_TYPE): array {
		$config = $this->typeConfig(type: $type);
		$target = $this->resolveTarget(type: $type);
		if ($target === null) {
			return ['ok' => false, 'reason' => 'register/schema not configured', 'items' => []];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['ok' => false, 'reason' => 'ObjectService unavailable', 'items' => []];
		}

		try {
			$objects = $objectService->searchObjects(
				query: [
					'@self' => ['register' => $target['register'], 'schema' => $target['schema']],
					$config['statusField'] => self::STATUS_PENDING,
					'_limit' => 500,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('ModerationService: listPending failed', ['type' => $type, 'error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => 'query failed', 'items' => []];
		}

		$items = [];
		$objectList = [];
		if (is_array($objects) === true) {
			$objectList = $objects;
		}

		foreach ($objectList as $object) {
			$items[] = $this->toDataBag(object: $object);
		}

		return ['ok' => true, 'reason' => 'ok', 'items' => $items];
	}//end listPending()

	/**
	 * Approve a pending entry: set it to the type's "approved" value and, for
	 * organisatie only, publish it (`publicatiedatum = now`) so the public
	 * RBAC read gate makes it anonymously visible.
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type (default: organisatie).
	 *
	 * @return array{ok:bool, reason:string, status:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function approve(string $uuid, string $type = self::MODERATED_TYPE): array {
		$config = $this->typeConfig(type: $type);
		return $this->decide(
			uuid: $uuid,
			type: $type,
			mutator: static function (array $data) use ($config): array {
				$data[$config['statusField']] = $config['approvedValue'];
				if ($config['stampPublication'] === true) {
					$data['publicationDate'] = gmdate('Y-m-d\TH:i:sP');
					$data['depublicationDate'] = null;
				}

				return $data;
			},
			status: $config['approvedValue'],
			action: 'approved'
		);
	}//end approve()

	/**
	 * Reject a pending entry: set it to the type's "rejected" value; for
	 * organisatie, never give it a `publicatiedatum` (stays invisible).
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type (default: organisatie).
	 *
	 * @return array{ok:bool, reason:string, status:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function reject(string $uuid, string $type = self::MODERATED_TYPE): array {
		$config = $this->typeConfig(type: $type);
		return $this->decide(
			uuid: $uuid,
			type: $type,
			mutator: static function (array $data) use ($config): array {
				$data[$config['statusField']] = self::STATUS_REJECTED;
				if ($config['stampPublication'] === true) {
					$data['publicationDate'] = null;
				}

				return $data;
			},
			status: self::STATUS_REJECTED,
			action: 'rejected'
		);
	}//end reject()

	/**
	 * Per-type moderation configuration: which field carries the moderation
	 * state, what its "approved" value is, and whether approval also stamps
	 * `publicatiedatum` (organisatie only — beoordeeling's public visibility
	 * is governed entirely by its own `status` field via the schema RBAC
	 * rule, no publication date involved).
	 *
	 * @param string $type The moderated object type.
	 *
	 * @return array{statusField:string, approvedValue:string, stampPublication:bool} The config.
	 */
	private function typeConfig(string $type): array {
		if ($type === self::MODERATED_TYPE_REVIEW) {
			return ['statusField' => 'status', 'approvedValue' => self::STATUS_APPROVED, 'stampPublication' => false];
		}

		return ['statusField' => 'registrationStatus', 'approvedValue' => self::STATUS_ACTIVE, 'stampPublication' => true];
	}//end typeConfig()

	/**
	 * Apply a moderation decision to a pending entry.
	 *
	 * Only an entry that is currently `pending` may be decided — this keeps
	 * the action idempotent and prevents re-deciding an already-approved
	 * entry (which would re-stamp its publicatiedatum for organisatie).
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type.
	 * @param callable(array<string,mixed>):array<string,mixed> $mutator The state mutation.
	 * @param string $status The resulting status.
	 * @param string $action Log label.
	 *
	 * @return array{ok:bool, reason:string, status:?string} Result.
	 */
	private function decide(string $uuid, string $type, callable $mutator, string $status, string $action): array {
		$config = $this->typeConfig(type: $type);
		$target = $this->resolveTarget(type: $type);
		if ($target === null) {
			return ['ok' => false, 'reason' => 'register/schema not configured', 'status' => null];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['ok' => false, 'reason' => 'ObjectService unavailable', 'status' => null];
		}

		try {
			$entity = $objectService->find(
				id: $uuid,
				register: (string)$target['register'],
				schema: (string)$target['schema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return ['ok' => false, 'reason' => 'entry not found', 'status' => null];
		}

		if ($entity === null) {
			return ['ok' => false, 'reason' => 'entry not found', 'status' => null];
		}

		$data = $this->toDataBag(object: $entity);

		// A peer-sourced (federated) mirror is never moderated locally.
		if (is_array($data['_source'] ?? null) === true && trim((string)($data['_source']['instance'] ?? '')) !== '') {
			return ['ok' => false, 'reason' => 'peer-sourced entries cannot be moderated locally', 'status' => null];
		}

		if (($data[$config['statusField']] ?? null) !== self::STATUS_PENDING) {
			return ['ok' => false, 'reason' => 'entry is not pending moderation', 'status' => null];
		}

		$data = $mutator($data);
		unset($data['id']);

		try {
			$objectService->saveObject(
				object: $data,
				register: $target['register'],
				schema: $target['schema'],
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->logger->error('ModerationService: ' . $action . ' failed', ['type' => $type, 'uuid' => $uuid, 'error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => 'could not update entry', 'status' => null];
		}

		$this->logger->info('ModerationService: entry ' . $action, ['type' => $type, 'uuid' => $uuid, 'status' => $status]);
		return ['ok' => true, 'reason' => $action, 'status' => $status];
	}//end decide()

	/**
	 * Resolve the moderated register/schema for a given type.
	 *
	 * @param string $type The moderated object type.
	 *
	 * @return array{register:int, schema:int}|null The target, or null.
	 */
	private function resolveTarget(string $type): ?array {
		$register = $this->settingsService->getRegisterIdForObjectType($type);
		$schema = $this->settingsService->getSchemaIdForObjectType($type);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => (int)$register, 'schema' => (int)$schema];
	}//end resolveTarget()

	/**
	 * Normalise an ObjectService result item to a data bag (with its uuid).
	 *
	 * @param mixed $object The result item (ObjectEntity or array).
	 *
	 * @return array<string,mixed> The data bag.
	 */
	private function toDataBag(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {
				$data['id'] = $object->getUuid();
			}

			if (is_array($data) === true) {
				return $data;
			}

			return [];
		}

		return [];
	}//end toDataBag()

	/**
	 * Get the OpenRegister ObjectService from the DI container.
	 *
	 * @return object|null The object service, or null when OR is absent.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('ModerationService: ObjectService unavailable', ['error' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
