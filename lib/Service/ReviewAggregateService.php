<?php

/**
 * Public approved-only review aggregate/read path (catalog-ratings,
 * stackiq#375) — split out of ReviewService (which owns the
 * authenticated write path) to keep each class under the
 * ExcessiveClassComplexity budget.
 *
 * The module/dienst aggregate (average + count) and approved-review listing
 * are computed here in PHP against `ObjectService::searchObjects()` results
 * rather than through a declarative manifest aggregation-widget filter,
 * because `beoordeeling.modules`/`diensten` are ARRAYS of related-object
 * references and this app has no existing precedent confirming that the
 * aggregation backend's declarative `filter` supports array-containment
 * matching — computing it here is fully unit-testable regardless of that.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Approved-only aggregate (average + count) + a bounded review list.
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
 */
class ReviewAggregateService {
	/**
	 * The catalog object type reviews live on.
	 */
	public const REVIEW_TYPE = 'software-review';

	/**
	 * Public-visible moderation state.
	 */
	public const STATUS_APPROVED = 'approved';

	/**
	 * Subject types a review may be attached to.
	 *
	 * @var array<int,string>
	 */
	public const SUBJECT_TYPES = ['module', 'catalogService'];

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
	 * The approved-only aggregate (average + count) and a bounded list of
	 * approved reviews for a module or dienst.
	 *
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst.
	 *
	 * @return array{ok:bool, reason:string, average:?float, count:int, items:array<int,array<string,mixed>>} Result.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	 */
	public function getAggregate(string $subjectType, string $subjectId): array {
		if (in_array($subjectType, self::SUBJECT_TYPES, true) === false) {
			return ['ok' => false, 'reason' => 'invalid subject type', 'average' => null, 'count' => 0, 'items' => []];
		}

		$approved = $this->fetchApprovedReviews();
		if ($approved['ok'] === false) {
			return ['ok' => false, 'reason' => $approved['reason'], 'average' => null, 'count' => 0, 'items' => []];
		}

		$relationField = 'diensten';
		if ($subjectType === 'module') {
			$relationField = 'modules';
		}

		$matched = $this->filterApprovedForSubject(
			approvedReviews: $approved['items'],
			relationField: $relationField,
			subjectId: $subjectId
		);

		return [
			'ok' => true,
			'reason' => 'ok',
			'average' => $this->averageRating(reviews: $matched),
			'count' => count($matched),
			'items' => array_slice($matched, 0, 10),
		];
	}//end getAggregate()

	/**
	 * Query every approved review (bounded, RBAC-bypassed — this method
	 * self-applies the `status = approved` predicate, which is exactly what
	 * the schema's own public RBAC rule allows anonymous readers to see).
	 *
	 * @return array{ok:bool, reason:string, items:array<int,array<string,mixed>>} Result.
	 */
	private function fetchApprovedReviews(): array {
		$target = $this->resolveTarget();
		if ($target === null) {
			return ['ok' => false, 'reason' => 'review register/schema not configured', 'items' => []];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['ok' => false, 'reason' => 'ObjectService unavailable', 'items' => []];
		}

		try {
			$objects = $objectService->searchObjects(
				query: [
					'@self' => ['register' => $target['register'], 'schema' => $target['schema']],
					'status' => self::STATUS_APPROVED,
					'_limit' => 1000,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('ReviewAggregateService: aggregate query failed', ['error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => 'query failed', 'items' => []];
		}

		$objectList = [];
		if (is_array($objects) === true) {
			$objectList = $objects;
		}

		// Re-check `status` in PHP even though it was also passed as a query
		// predicate above: `_rbac: false` bypasses OpenRegister's own
		// enforcement of that predicate on some ObjectService implementations,
		// so this is the actual enforcement point, not defensive redundancy.
		$items = [];
		foreach ($objectList as $object) {
			$data = $this->toDataBag(object: $object);
			if (($data['status'] ?? null) === self::STATUS_APPROVED) {
				$items[] = $data;
			}
		}

		return ['ok' => true, 'reason' => 'ok', 'items' => $items];
	}//end fetchApprovedReviews()

	/**
	 * Narrow an already-approved review list down to the ones referencing
	 * the given subject.
	 *
	 * @param array<int,array<string,mixed>> $approvedReviews The approved reviews.
	 * @param string $relationField 'modules' or 'diensten'.
	 * @param string $subjectId The uuid being looked for.
	 *
	 * @return array<int,array<string,mixed>> The matching reviews.
	 */
	private function filterApprovedForSubject(array $approvedReviews, string $relationField, string $subjectId): array {
		$matched = [];
		foreach ($approvedReviews as $data) {
			$relationValue = ($data[$relationField] ?? null);
			if ($this->relationContainsSubject(relationValue: $relationValue, subjectId: $subjectId) === true) {
				$matched[] = $data;
			}
		}

		return $matched;
	}//end filterApprovedForSubject()

	/**
	 * Whether a related-object array/scalar value references the given
	 * subject id. Tolerates both plain-uuid-array and nested-object-array
	 * storage shapes (`["<uuid>"]` or `[{"id":"<uuid>"}]`) since this app has
	 * no single confirmed convention for a `related-object` array property.
	 *
	 * @param mixed $relationValue The raw property value.
	 * @param string $subjectId The uuid being looked for.
	 *
	 * @return bool True when the subject is referenced.
	 */
	private function relationContainsSubject(mixed $relationValue, string $subjectId): bool {
		if (is_array($relationValue) === false) {
			return false;
		}

		foreach ($relationValue as $entry) {
			if (is_string($entry) === true && $entry === $subjectId) {
				return true;
			}

			if (is_array($entry) === true) {
				$entryId = $entry['id'] ?? $entry['uuid'] ?? null;
				if (is_string($entryId) === true && $entryId === $subjectId) {
					return true;
				}
			}
		}

		return false;
	}//end relationContainsSubject()

	/**
	 * The average `waardering` across a review list, or null when empty.
	 *
	 * @param array<int,array<string,mixed>> $reviews The reviews to average.
	 *
	 * @return float|null The rounded average, or null when $reviews is empty.
	 */
	private function averageRating(array $reviews): ?float {
		$count = count($reviews);
		if ($count === 0) {
			return null;
		}

		$sum = 0.0;
		foreach ($reviews as $review) {
			$sum += (float)($review['rating'] ?? 0);
		}

		return round($sum / $count, 2);
	}//end averageRating()

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
			$this->logger->error('ReviewAggregateService: ObjectService unavailable', ['error' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
