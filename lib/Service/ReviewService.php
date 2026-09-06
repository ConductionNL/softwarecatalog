<?php

/**
 * Authenticated review submission (write path only — see
 * ReviewAggregateService for the approved-only read/aggregate path; split
 * to keep each class under the ExcessiveClassComplexity budget).
 *
 * The write path for the `catalog-ratings` feature (stackiq#375).
 * Unlike `IntakeService` (anonymous organisation registration), a review
 * submission REQUIRES an authenticated Nextcloud session — anonymous public
 * review submission is explicitly out of scope. The author identity is never
 * taken from the client: `auteur` (and every other privileged/system key) is
 * stripped from the incoming payload and `auteur` is re-derived from
 * `IUserSession::getUser()->getDisplayName()` before the object is
 * persisted, and every submission is forced to `status = pending` — only an
 * admin moderation decision (`ModerationService`, reusing the existing
 * organisatie moderation pattern) may change that.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Authenticated submission of a beoordeeling (review).
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ReviewService {
	/**
	 * The catalog object type reviews live on.
	 */
	public const REVIEW_TYPE = 'software-review';

	/**
	 * Moderation state of a freshly-submitted review — mirrors
	 * `ModerationService::STATUS_PENDING` but the two are intentionally
	 * decoupled constants (different schemas, different field names).
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Subject types a review may be attached to.
	 *
	 * @var array<int,string>
	 */
	public const SUBJECT_TYPES = ['module', 'catalogService'];

	/**
	 * Required fields on a review submission payload.
	 *
	 * @var array<int,string>
	 */
	public const REQUIRED_FIELDS = ['name', 'rating'];

	/**
	 * Maximum number of fields accepted on a submission (anti-abuse).
	 */
	public const MAX_FIELDS = 30;

	/**
	 * Maximum length of any single string value (anti-abuse).
	 */
	public const MAX_FIELD_LENGTH = 5000;

	/**
	 * Caller-controlled keys that MUST be stripped from a submission — the
	 * author, moderation state, identifiers, ownership, and provenance are
	 * always server-controlled, never client-supplied.
	 *
	 * @var array<int,string>
	 */
	public const FORBIDDEN_KEYS = [
		'auteur',
		'status',
		'id',
		'uuid',
		'_owner',
		'_organisation',
		'_source',
		'modules',
		'diensten',
		'koppelingen',
		'usage',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param SettingsService $settingsService Resolves register/schema ids.
	 * @param IUserSession $userSession The Nextcloud user session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Submit an authenticated review into the moderation queue.
	 *
	 * Requires an active session (never anonymous). Strips every
	 * client-controlled privileged key, re-derives `auteur` from the
	 * session, binds the subject (`modules`/`diensten`), and forces
	 * `status = pending`.
	 *
	 * @param array<string,mixed> $payload The review payload
	 *                                     (naam, waardering,
	 *                                     beschrijvingKort/Lang).
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst being reviewed.
	 *
	 * @return array{ok:bool, reason:string, uuid:?string, status:?string} Result.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
	 */
	public function submit(array $payload, string $subjectType, string $subjectId): array {
		$user = $this->userSession->getUser();
		$reason = $this->guardSubmission(user: $user, subjectType: $subjectType, subjectId: $subjectId, payload: $payload);
		if ($reason !== null) {
			return ['ok' => false, 'reason' => $reason, 'uuid' => null, 'status' => null];
		}

		$target = $this->resolveTarget();
		if ($target === null) {
			return ['ok' => false, 'reason' => 'review register/schema not configured', 'uuid' => null, 'status' => null];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['ok' => false, 'reason' => 'ObjectService unavailable', 'uuid' => null, 'status' => null];
		}

		// $user is guaranteed non-null past guardSubmission()'s "not authenticated" check.
		$clean = $this->buildSubmissionObject(payload: $payload, user: $user, subjectType: $subjectType, subjectId: $subjectId);

		try {
			$entity = $objectService->saveObject(
				object: $clean,
				register: $target['register'],
				schema: $target['schema']
			);
		} catch (\Throwable $e) {
			$this->logger->error('ReviewService: submit failed', ['error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => 'could not store review', 'uuid' => null, 'status' => null];
		}

		$uuid = $this->entityUuid(entity: $entity);
		$this->logger->info(
			'ReviewService: review queued (pending)',
			['uuid' => $uuid, 'auteur' => $clean['auteur'], 'subjectType' => $subjectType, 'subjectId' => $subjectId]
		);

		return ['ok' => true, 'reason' => 'queued for moderation', 'uuid' => $uuid, 'status' => self::STATUS_PENDING];
	}//end submit()

	/**
	 * Pre-flight guards for a submission: authentication, subject shape, and
	 * payload validation. Split out of submit() to keep both methods under
	 * the cyclomatic-complexity budget.
	 *
	 * @param IUser|null $user The authenticated user, or null.
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst.
	 * @param array<string,mixed> $payload The raw review payload.
	 *
	 * @return string|null The rejection reason, or null when the submission may proceed.
	 */
	private function guardSubmission(?IUser $user, string $subjectType, string $subjectId, array $payload): ?string {
		if ($user === null) {
			return 'not authenticated';
		}

		if (in_array($subjectType, self::SUBJECT_TYPES, true) === false) {
			return 'invalid subject type';
		}

		if (trim($subjectId) === '') {
			return 'subject id is required';
		}

		return $this->validate(payload: $payload);
	}//end guardSubmission()

	/**
	 * Build the object to persist: sanitised payload + server-derived
	 * author, forced pending status, and the subject binding.
	 *
	 * @param array<string,mixed> $payload The raw review payload.
	 * @param IUser $user The authenticated user.
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst.
	 *
	 * @return array<string,mixed> The object ready for ObjectService::saveObject().
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
	 */
	private function buildSubmissionObject(array $payload, IUser $user, string $subjectType, string $subjectId): array {
		$clean = $this->sanitise(payload: $payload);

		// Author identity is ALWAYS re-derived from the session — a
		// client-supplied `auteur` was already stripped above and is never
		// read back from $payload.
		$displayName = trim($user->getDisplayName());
		$authorName = $user->getUID();
		if ($displayName !== '') {
			$authorName = $displayName;
		}

		$clean['auteur'] = $authorName;
		$clean['status'] = self::STATUS_PENDING;

		$relationField = 'diensten';
		if ($subjectType === 'module') {
			$relationField = 'modules';
		}

		$clean[$relationField] = [$subjectId];

		return $clean;
	}//end buildSubmissionObject()

	/**
	 * Validate a review payload (anti-abuse + required fields).
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return string|null The rejection reason, or null when valid.
	 */
	private function validate(array $payload): ?string {
		if ($payload === []) {
			return 'empty payload';
		}

		if (count($payload) > self::MAX_FIELDS) {
			return 'too many fields';
		}

		$reason = $this->validateRequiredFields(payload: $payload);
		if ($reason !== null) {
			return $reason;
		}

		$reason = $this->validateRating(payload: $payload);
		if ($reason !== null) {
			return $reason;
		}

		return $this->validateFieldSizes(payload: $payload);
	}//end validate()

	/**
	 * Every field in REQUIRED_FIELDS is present and non-empty.
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return string|null The rejection reason, or null when valid.
	 */
	private function validateRequiredFields(array $payload): ?string {
		foreach (self::REQUIRED_FIELDS as $field) {
			if (array_key_exists($field, $payload) === false || $payload[$field] === null || $payload[$field] === '') {
				return 'missing required field: ' . $field;
			}
		}

		return null;
	}//end validateRequiredFields()

	/**
	 * `waardering` is numeric and within the 1-10 range.
	 *
	 * @param array<string,mixed> $payload The payload (REQUIRED_FIELDS already confirmed present).
	 *
	 * @return string|null The rejection reason, or null when valid.
	 */
	private function validateRating(array $payload): ?string {
		$rating = $payload['rating'];
		if (is_numeric($rating) === false || (int)$rating < 1 || (int)$rating > 10) {
			return 'rating must be between 1 and 10';
		}

		return null;
	}//end validateRating()

	/**
	 * No string value exceeds MAX_FIELD_LENGTH (anti-abuse).
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return string|null The rejection reason, or null when valid.
	 */
	private function validateFieldSizes(array $payload): ?string {
		foreach ($payload as $value) {
			if (is_string($value) === true && strlen($value) > self::MAX_FIELD_LENGTH) {
				return 'field value exceeds the maximum length';
			}
		}

		return null;
	}//end validateFieldSizes()

	/**
	 * Strip caller-controlled / privileged keys from a submission payload.
	 *
	 * @param array<string,mixed> $payload The raw payload.
	 *
	 * @return array<string,mixed> The sanitised payload.
	 */
	private function sanitise(array $payload): array {
		foreach (self::FORBIDDEN_KEYS as $key) {
			unset($payload[$key]);
		}

		return $payload;
	}//end sanitise()

	/**
	 * Resolve the register/schema reviews live in.
	 *
	 * @return array{register:int, schema:int}|null The target, or null.
	 */
	private function resolveTarget(): ?array {
		$register = $this->settingsService->getRegisterIdForObjectType(self::REVIEW_TYPE);
		$schema = $this->settingsService->getSchemaIdForObjectType(self::REVIEW_TYPE);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => (int)$register, 'schema' => (int)$schema];
	}//end resolveTarget()

	/**
	 * The uuid of a saved entity (handles entity or array result shapes).
	 *
	 * `ObjectService::saveObject()` returns an `ObjectEntity`, whose
	 * `getUuid()` is an `@method` docblock served by `Entity::__call()` over
	 * `protected ?string $uuid`. A bare `method_exists()` probe is therefore
	 * FALSE, and because an object is not an array the array arm below cannot
	 * rescue it — so this method used to return `null` for EVERY real save,
	 * putting `uuid: null` in the submit response and the audit log
	 * (stackiq#490). `property_exists()` is the instrument
	 * `Entity::getter()` itself decides on; `method_exists()` is kept as the
	 * second arm for genuinely concrete accessors, and the call is wrapped
	 * because neither probe guarantees the other object's shape.
	 *
	 * @param mixed $entity The saveObject result.
	 *
	 * @return string|null The uuid, or null.
	 */
	private function entityUuid(mixed $entity): ?string {
		if (is_object($entity) === true
			&& (property_exists($entity, 'uuid') === true || method_exists($entity, 'getUuid') === true)
		) {
			try {
				$uuid = $entity->getUuid();
			} catch (\Throwable $e) {
				$this->logger->warning('ReviewService: could not read uuid from saved entity', ['exception' => $e->getMessage()]);
				return null;
			}

			if (is_string($uuid) === true && $uuid !== '') {
				return $uuid;
			}

			return null;
		}

		if (is_array($entity) === true) {
			$uuid = $entity['id'] ?? $entity['uuid'] ?? null;
			if (is_string($uuid) === true) {
				return $uuid;
			}

			return null;
		}

		return null;
	}//end entityUuid()

	/**
	 * Get the OpenRegister ObjectService from the DI container.
	 *
	 * @return object|null The object service, or null when OR is absent.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('ReviewService: ObjectService unavailable', ['error' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
