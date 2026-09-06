<?php

/**
 * Facet Service for Stackiq
 *
 * Aggregates GEMMA-dimension facet counts (referenceComponent, standard,
 * applicationService, domain) across the `module` and `dienst` listings,
 * scoped to the caller's RBAC/tenant context and combinable with the
 * existing free-text search.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Facet Service for GEMMA-dimension aggregation.
 *
 * Architecture (see design.md): resolves a bounded, RBAC-scoped candidate
 * object set for the requested schema (`module` or `dienst`), builds a
 * per-object map of GEMMA dimension values (resolving `domain` and
 * `applicationService` via linked `element` lookups through the existing
 * `ArchiMateService`), then computes disjunctive ("self-count not narrowed
 * by its own selection") facet counts over that map.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) 1095 lines (threshold 1000). This is a real
 * smell, not a defensible shape: the class currently holds three separable concerns — candidate
 * resolution + RBAC scoping, GEMMA dimension mapping via ArchiMate element lookups, and the
 * disjunctive count aggregation. It SHOULD be split along those seams (the aggregation half in
 * particular has no dependency on the OpenRegister/ArchiMate plumbing). Recorded here as a
 * deliberate follow-up; splitting it is a behaviour-affecting refactor and is out of scope for a
 * quality-gate-only change.
 */
class FacetService {

	/**
	 * Distributed cache for facet responses.
	 *
	 * @var ICache
	 */
	private ICache $facetsCache;

	/**
	 * Cache TTL in seconds (30 minutes) — matches ViewService::CACHE_TTL.
	 */
	private const CACHE_TTL = 1800;

	/**
	 * Objects fetched per bounded page when resolving the base module/dienst set.
	 */
	private const BASE_OBJECT_LIMIT = 1000;

	/**
	 * Documented paging ceiling for the base object set (bound-unbounded-searchobjects-scans
	 * pattern): at most this many pages of BASE_OBJECT_LIMIT are read, i.e. at most
	 * BASE_OBJECT_LIMIT * MAX_BASE_PAGES objects are ever aggregated over. A register
	 * larger than that is paged (never scanned unbounded) and a warning is logged.
	 */
	private const MAX_BASE_PAGES = 5;

	/**
	 * Bounded limit for element/relation lookup queries (domain/applicationService resolution).
	 */
	private const ELEMENT_LOOKUP_LIMIT = 1000;

	/**
	 * Facet schemas this service supports.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_SCHEMAS = ['module', 'catalogService'];

	/**
	 * GEMMA dimensions always present in the response (even when empty).
	 *
	 * @var string[]
	 */
	private const DIMENSIONS = ['referenceComponent', 'standard', 'applicationService', 'domain'];

	/**
	 * Constructor for FacetService.
	 *
	 * @param ContainerInterface $container PSR-11 container interface (for lazy ObjectService lookup).
	 * @param SettingsService $settingsService Settings service for voorzieningen register/schema configuration.
	 * @param ArchiMateService $archiMateService Reused for bounded `element`/`relationship` lookups
	 *                                           (domain/applicationService resolution) instead of
	 *                                           duplicating AMEF register/schema resolution logic.
	 * @param ViewQueryBuilder $queryBuilder Reused for the `_search` query-param convention.
	 * @param IUserSession $userSession User session service for current user/RBAC cache-key context.
	 * @param LoggerInterface $logger Logger service.
	 * @param ICacheFactory $cacheFactory Cache factory for distributed caching.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly ArchiMateService $archiMateService,
		private readonly ViewQueryBuilder $queryBuilder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->facetsCache = $cacheFactory->createDistributed(prefix: 'stackiq_facets');

	}//end __construct()

	/**
	 * Get GEMMA-dimension facet counts for a schema.
	 *
	 * @param string $schema `module` or `dienst`.
	 * @param array $filters Currently-selected facet values keyed by dimension,
	 *                       e.g. `['referenceComponent' => ['Zaakregistratiecomponent']]`.
	 * @param string|null $search Free-text query narrowing the candidate set.
	 * @param string|null $organization Optional organisation override (mirrors
	 *                                  view-enrichment-api's `organization` parameter).
	 *
	 * @throws InvalidArgumentException When `$schema` is not supported.
	 * @throws RuntimeException When OpenRegister's ObjectService is unavailable.
	 *
	 * @return array{referenceComponent: array, standard: array, applicationService: array,
	 *     domain: array, _meta: array{totalMatched: int, processingTimeMs: float, cached: bool,
	 *     matchedObjectIds: string[]}}
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
	 */
	public function getFacets(string $schema, array $filters = [], ?string $search = null, ?string $organization = null): array {
		$this->assertSupportedSchema(schema: $schema);

		$normalizedFilters = $this->normalizeFilters(filters: $filters);

		$normalizedSearch = null;
		if ($search !== null && trim($search) !== '') {
			$normalizedSearch = trim($search);
		}

		$cacheKey = $this->buildCacheKey(
			schema: $schema,
			filters: $normalizedFilters,
			search: $normalizedSearch,
			organization: $organization
		);

		$cached = $this->facetsCache->get(key: $cacheKey);
		if (is_array($cached) === true) {
			$cached['_meta']['cached'] = true;
			return $cached;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister ObjectService not available');
		}

		$result = $this->computeFacetsForRequest(
			objectService: $objectService,
			schema: $schema,
			normalizedFilters: $normalizedFilters,
			normalizedSearch: $normalizedSearch,
			organization: $organization
		);

		$this->facetsCache->set(key: $cacheKey, value: $result, ttl: self::CACHE_TTL);

		return $result;
	}//end getFacets()

	/**
	 * Validate the `$schema` path segment against the supported set.
	 *
	 * @param string $schema The requested facet schema.
	 *
	 * @throws InvalidArgumentException When `$schema` is not supported.
	 *
	 * @return void
	 */
	private function assertSupportedSchema(string $schema): void {
		if (in_array($schema, self::SUPPORTED_SCHEMAS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'Unsupported facet schema "%s". Supported schemas: %s.',
					$schema,
					implode(', ', self::SUPPORTED_SCHEMAS)
				)
			);
		}

	}//end assertSupportedSchema()

	/**
	 * Compute the full facet response for a cache-miss request: fetch the
	 * bounded RBAC/search-scoped base object set, resolve GEMMA dimension
	 * values, compute disjunctive counts, and assemble the response
	 * (including `_meta.matchedObjectIds` for the frontend's list narrowing).
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param string $schema `module` or `dienst`.
	 * @param array<string,string[]> $normalizedFilters Normalized selected filters.
	 * @param string|null $normalizedSearch Normalized free-text query.
	 * @param string|null $organization Optional organisation override.
	 *
	 * @return array{referenceComponent: array, standard: array, applicationService: array,
	 *     domain: array, _meta: array{totalMatched: int, processingTimeMs: float, cached: bool,
	 *     matchedObjectIds: string[]}}
	 */
	private function computeFacetsForRequest(
		ObjectServiceInterface $objectService,
		string $schema,
		array $normalizedFilters,
		?string $normalizedSearch,
		?string $organization,
	): array {
		$startTime = microtime(true);

		$baseObjects = $this->fetchBaseObjects(
			objectService: $objectService,
			schema: $schema,
			search: $normalizedSearch,
			organization: $organization
		);

		$modulesByObjectId = $this->resolveModulesPerObject(
			objectService: $objectService,
			schema: $schema,
			baseObjects: $baseObjects
		);

		$dimValsByObjId = $this->buildDimensionValueMap(modulesByObjectId: $modulesByObjectId);

		$facets = $this->computeFacets(
			dimValsByObjId: $dimValsByObjId,
			selectedFilters: $normalizedFilters
		);

		$matchedObjectIds = $this->filterObjectIds(
			dimValsByObjId: $dimValsByObjId,
			selectedFilters: $normalizedFilters,
			excludeDimension: null
		);

		$processingTimeMs = round((microtime(true) - $startTime) * 1000, 2);

		// `matchedObjectIds` — the RBAC/filter/search-scoped object id set this
		// response's counts describe (proposal.md's Approach: "the RBAC-filtered
		// object IDs needed to drive the index page's existing list query").
		// Several dimensions (`domain`, `applicationService`, and `referenceComponent`/
		// `standard` by display NAME) are not directly filterable on the
		// `module`/`dienst` schema itself, so the frontend narrows its own object
		// list via `{ id: matchedObjectIds }` rather than re-deriving an
		// equivalent filter from the facet selection. Already bounded — this id
		// list can never exceed BASE_OBJECT_LIMIT * MAX_BASE_PAGES entries.
		$result = array_merge(
			$facets,
			[
				'_meta' => [
					'totalMatched' => count($matchedObjectIds),
					'processingTimeMs' => $processingTimeMs,
					'cached' => false,
					'matchedObjectIds' => array_values($matchedObjectIds),
				],
			]
		);

		$this->logger->debug(
			message: 'FacetService: computed facets',
			context: [
				'schema' => $schema,
				'candidateObjects' => count($baseObjects),
				'totalMatched' => $result['_meta']['totalMatched'],
				'processingTimeMs' => $processingTimeMs,
			]
		);

		return $result;
	}//end computeFacetsForRequest()

	/**
	 * Normalize the incoming filters array to `dimension => string[]`, dropping
	 * unknown dimensions and empty/blank values.
	 *
	 * @param array $filters Raw filters keyed by dimension.
	 *
	 * @return array<string,string[]> Normalized filters (only known dimensions, non-empty values).
	 */
	private function normalizeFilters(array $filters): array {
		$normalized = [];

		foreach (self::DIMENSIONS as $dimension) {
			$values = $filters[$dimension] ?? null;
			if ($values === null) {
				continue;
			}

			if (is_array($values) === false) {
				$values = [$values];
			}

			$cleanValues = [];
			foreach ($values as $value) {
				if (is_string($value) === true && trim($value) !== '') {
					$cleanValues[] = trim($value);
				}
			}

			if (empty($cleanValues) === false) {
				$normalized[$dimension] = array_values(array_unique($cleanValues));
			}
		}//end foreach

		return $normalized;
	}//end normalizeFilters()

	/**
	 * Build a cache key covering every query-affecting parameter, including the
	 * caller's RBAC/tenant context — two users MUST NOT collide on the same key.
	 *
	 * @param string $schema Facet schema.
	 * @param array<string,string[]> $filters Normalized filters.
	 * @param string|null $search Free-text query.
	 * @param string|null $organization Organisation override.
	 *
	 * @return string Cache key.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
	 */
	private function buildCacheKey(string $schema, array $filters, ?string $search, ?string $organization): string {
		$user = $this->userSession->getUser();

		$userId = 'anonymous';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		ksort($filters);
		foreach (array_keys($filters) as $dimension) {
			sort($filters[$dimension]);
		}

		$keyData = [
			'schema' => $schema,
			'filters' => $filters,
			'search' => $search,
			'organization' => $organization ?? $this->getCurrentOrganisation(),
			'user' => $userId,
		];

		return 'facets_' . md5(json_encode($keyData));
	}//end buildCacheKey()

	/**
	 * Fetch the bounded, RBAC/tenant-scoped, search-filtered candidate object set
	 * for the requested schema. Pages via `searchObjectsPaginated()` up to the
	 * documented `MAX_BASE_PAGES` ceiling instead of ever issuing a single
	 * unbounded `searchObjects()` call.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param string $schema `module` or `dienst`.
	 * @param string|null $search Free-text query.
	 * @param string|null $organization Organisation override.
	 *
	 * @return array<int,array> The candidate objects.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
	 */
	private function fetchBaseObjects(ObjectServiceInterface $objectService, string $schema, ?string $search, ?string $organization): array {
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;
		// NOT `$schema . '_schema'`: the slugs are English now and the stored
		// config keys are still Dutch, so the derived key would miss and this
		// method would log "not configured" and return an empty facet set —
		// which renders as a page with no filters rather than as an error.
		//
		// Read as a CONSTANT rather than through SettingsService: this class
		// takes that service as a collaborator and every test mocks it, so a
		// method call here returns the mock's default and the lookup misses in
		// exactly the tests meant to catch that.
		$schemaKey = (SettingsService::LEGACY_SCHEMA_KEY[$schema] ?? $schema . '_schema');
		$schemaId = ($voorzieningenConfig[$schemaKey] ?? null);

		if (empty($registerId) === true || empty($schemaId) === true) {
			$this->logger->warning(
				message: 'FacetService: voorzieningen register/schema not configured',
				context: ['schema' => $schema]
			);
			return [];
		}

		$baseQuery = [
			'@self' => [
				'register' => (int)$registerId,
				'schema' => (int)$schemaId,
			],
		];

		// RBAC/tenant scoping: identical convention to ViewService — an explicit
		// organisation filter, `_rbac`/`_multitenancy` left at their `true`
		// defaults (no separate unscoped counting path).
		$orgToApply = $organization ?? $this->getCurrentOrganisation();
		if ($orgToApply !== null) {
			$baseQuery['@self']['organisation'] = $orgToApply;
		}

		$baseQuery = $this->queryBuilder->applySearchFilter(baseQuery: $baseQuery, search: $search);

		$allObjects = [];
		$page = 1;

		while ($page <= self::MAX_BASE_PAGES) {
			$pagedQuery = $baseQuery;
			$pagedQuery['_limit'] = self::BASE_OBJECT_LIMIT;
			$pagedQuery['_page'] = $page;

			$paginated = $objectService->searchObjectsPaginated($pagedQuery);
			$results = array_map(fn (mixed $entry): array => $this->normalizeObject(object: $entry), ($paginated['results'] ?? []));
			$allObjects = array_merge($allObjects, $results);

			$totalPages = (int)($paginated['pages'] ?? 1);
			if (count($results) < self::BASE_OBJECT_LIMIT || $page >= $totalPages) {
				break;
			}

			$page++;
		}

		if ($page > self::MAX_BASE_PAGES) {
			$this->logger->warning(
				message: 'FacetService: base object set exceeded the documented paging ceiling — '
					. 'facet counts are computed over a bounded subset, not the full register',
				context: [
					'schema' => $schema,
					'maxPages' => self::MAX_BASE_PAGES,
					'limitPerPage' => self::BASE_OBJECT_LIMIT,
				]
			);
		}

		return $allObjects;
	}//end fetchBaseObjects()

	/**
	 * Normalize a single OpenRegister search result into a plain data-bag
	 * array, tolerant of the several shapes OpenRegister can hand back.
	 *
	 * `ObjectService::searchObjectsPaginated()`/`searchObjects()` return
	 * `OCA\OpenRegister\Db\ObjectEntity` instances in production (confirmed
	 * live: `ObjectEntity` given where an `array` was assumed), NOT plain
	 * arrays — only the unit-test stub returned arrays, which is how this
	 * shipped green and dead. This is the single boundary every OR search
	 * result MUST pass through before any other FacetService method (all of
	 * which are `array`-typed) touches it.
	 *
	 * Preference order: already an array → returned as-is. An `ObjectEntity`
	 * (or any object exposing `jsonSerialize()`) → `jsonSerialize()`, which
	 * merges the payload properties with `@self` metadata AND mirrors the id
	 * at the top level (see `ObjectEntity::jsonSerialize()`), so both the
	 * payload fields `extractRelatedIdentifiers()`/`extractRelatedNames()`
	 * read AND the `id`/`@self.id` shapes `objectIdentifier()` reads survive.
	 * An object exposing only `getObject()` → that (payload only, `id`
	 * still present at top level per `ObjectEntity::getObject()`). Anything
	 * else → cast to array as a last-resort fallback.
	 *
	 * @param mixed $object A single OpenRegister search result entry.
	 *
	 * @return array The normalized data-bag array.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 */
	private function normalizeObject(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			return (array)$object->getObject();
		}

		return (array)$object;
	}//end normalizeObject()

	/**
	 * Resolve the module object(s) backing each base object's GEMMA dimensions.
	 *
	 * For `module`, the object IS the module (identity map). For `dienst`, GEMMA
	 * links are only transitive via `dienst.modules` — the linked module objects
	 * are resolved with a single bounded batch lookup.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param string $schema `module` or `dienst`.
	 * @param array $baseObjects The candidate objects from `fetchBaseObjects()`.
	 *
	 * @return array<string,array<int,array>> Object id => list of module objects.
	 */
	private function resolveModulesPerObject(ObjectServiceInterface $objectService, string $schema, array $baseObjects): array {
		$modulesByObjectId = [];

		if ($schema === 'module') {
			foreach ($baseObjects as $module) {
				$objectId = $this->objectIdentifier(object: $module);
				$modulesByObjectId[$objectId] = [$module];
			}

			return $modulesByObjectId;
		}

		// $schema === 'catalogService': collect every referenced module identifier across
		// the bounded candidate set, then resolve them with one batch lookup.
		$moduleIdsByDienstId = [];
		$allModuleIds = [];

		foreach ($baseObjects as $service) {
			$dienstId = $this->objectIdentifier(object: $service);
			$moduleIds = $this->extractRelatedIdentifiers(object: $service, field: 'modules');

			$moduleIdsByDienstId[$dienstId] = $moduleIds;
			$allModuleIds = array_merge($allModuleIds, $moduleIds);
		}

		$modulesById = $this->fetchModulesByIdentifiers(
			objectService: $objectService,
			identifiers: array_values(array_unique($allModuleIds))
		);

		foreach ($moduleIdsByDienstId as $dienstId => $moduleIds) {
			$modules = [];
			foreach ($moduleIds as $moduleId) {
				if (isset($modulesById[$moduleId]) === true) {
					$modules[] = $modulesById[$moduleId];
				}
			}

			$modulesByObjectId[$dienstId] = $modules;
		}

		return $modulesByObjectId;
	}//end resolveModulesPerObject()

	/**
	 * Batch-fetch module objects by their OpenRegister object id, bounded by an
	 * explicit `_limit`.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param array $identifiers Distinct module object identifiers to resolve.
	 *
	 * @return array<string,array> Module id => module object.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 */
	private function fetchModulesByIdentifiers(ObjectServiceInterface $objectService, array $identifiers): array {
		if (empty($identifiers) === true) {
			return [];
		}

		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;
		$schemaId = $voorzieningenConfig['module_schema'] ?? null;

		if (empty($registerId) === true || empty($schemaId) === true) {
			return [];
		}

		$query = [
			'@self' => [
				'register' => (int)$registerId,
				'schema' => (int)$schemaId,
			],
			'id' => $identifiers,
			'_limit' => self::ELEMENT_LOOKUP_LIMIT,
		];

		try {
			$results = $objectService->searchObjects($query);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'FacetService: failed to batch-resolve linked modules',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			// Defensive: `searchObjects()`'s declared return type is
			// `array|int` (a `count`-mode caller could pass a shape that
			// resolves to an int); this call site is never in count mode,
			// but stay defensive rather than fatal on an unexpected shape.
			return [];
		}

		$byId = [];
		foreach (array_map(fn (mixed $entry): array => $this->normalizeObject(object: $entry), $results) as $module) {
			$key = $this->objectIdentifier(object: $module);
			$byId[$key] = $module;
		}

		return $byId;
	}//end fetchModulesByIdentifiers()

	/**
	 * Build the per-object GEMMA dimension value map: for every base object,
	 * the set of referenceComponent/standard names it carries directly, and
	 * the set of domain/applicationService names resolved transitively via its
	 * linked `element` objects.
	 *
	 * @param array<string,array<int,array>> $modulesByObjectId Object id => module objects.
	 *
	 * @return array<string,array<string,string[]>> Object id => dimension => distinct values.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 */
	private function buildDimensionValueMap(array $modulesByObjectId): array {
		// Pass 1: direct fields (referenceComponent identifiers + standard identifiers).
		$refCompIdsByObjId = [];
		$stdValsByObjId = [];
		$allRefCompIds = [];

		foreach ($modulesByObjectId as $objectId => $modules) {
			$refCompIds = [];
			$standards = [];

			foreach ($modules as $module) {
				$refCompIds = array_merge($refCompIds, $this->extractRelatedIdentifiers(object: $module, field: 'referenceComponents'));
				$standards = array_merge($standards, $this->extractRelatedNames(object: $module, field: 'standardVersions'));
			}

			$refCompIds = array_values(array_unique($refCompIds));
			$standards = array_values(array_unique(array_filter($standards)));

			$refCompIdsByObjId[$objectId] = $refCompIds;
			$stdValsByObjId[$objectId] = $standards;
			$allRefCompIds = array_merge($allRefCompIds, $refCompIds);
		}

		$allRefCompIds = array_values(array_unique($allRefCompIds));

		// Pass 2: resolve referenceComponent elements themselves (for the
		// referenceComponent facet's display value + the `domain` field).
		$elementsById = $this->resolveElementsByIdentifier(identifiers: $allRefCompIds);

		// Pass 3: resolve applicationService elements reachable via a `relation`
		// touching one of the referenceComponent elements.
		$appSvcNmByRefComp = $this->resolveApplicatieservicesForReferentiecomponenten(
			refCompIds: $allRefCompIds
		);

		// Assemble the final per-object dimension map.
		$dimValsByObjId = [];

		foreach ($modulesByObjectId as $objectId => $modules) {
			$refCompIds = $refCompIdsByObjId[$objectId] ?? [];

			$refCompNames = [];
			$domeinValues = [];
			$appSvcValues = [];

			foreach ($refCompIds as $refCompId) {
				$element = $elementsById[$refCompId] ?? null;

				// `elementDisplayName()` itself falls back to `$refCompId` when
				// `$element` carries no usable `name` — pass an empty element
				// array when unresolved so the same call covers both cases.
				$refCompNames[] = $this->elementDisplayName(
					element: $element ?? [],
					fallbackIdentifier: $refCompId
				);

				$domain = $element['domain'] ?? null;
				if (is_string($domain) === true && trim($domain) !== '') {
					$domeinValues[] = trim($domain);
				}

				foreach (($appSvcNmByRefComp[$refCompId] ?? []) as $appSvcName) {
					$appSvcValues[] = $appSvcName;
				}
			}

			$dimValsByObjId[$objectId] = [
				'referenceComponent' => array_values(array_unique($refCompNames)),
				'standard' => $stdValsByObjId[$objectId] ?? [],
				'domain' => array_values(array_unique($domeinValues)),
				'applicationService' => array_values(array_unique($appSvcValues)),
			];
		}//end foreach

		return $dimValsByObjId;
	}//end buildDimensionValueMap()

	/**
	 * Resolve `element` objects by identifier, bounded by an explicit `_limit`.
	 * Reuses `ArchiMateService::getElementObjects()` — the same AMEF register +
	 * schema resolution `ViewService`'s relationship-resolution helpers already
	 * use — rather than re-deriving the AMEF register/schema ids here.
	 *
	 * @param array $identifiers Distinct element identifiers to resolve.
	 *
	 * @return array<string,array> Element identifier => element object.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 */
	private function resolveElementsByIdentifier(array $identifiers): array {
		if (empty($identifiers) === true) {
			return [];
		}

		try {
			$elements = $this->archiMateService->getElementObjects(
				[
					'identifier' => $identifiers,
					'limit' => self::ELEMENT_LOOKUP_LIMIT,
				]
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'FacetService: failed to resolve linked elements',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}

		$byIdentifier = [];
		foreach ($elements as $element) {
			$identifier = $element['identifier'] ?? $this->objectIdentifier(object: $element);
			$byIdentifier[$identifier] = $element;
		}

		return $byIdentifier;
	}//end resolveElementsByIdentifier()

	/**
	 * Resolve, for each referenceComponent identifier, the distinct display
	 * names of `Applicatieservice`-typed `element` objects reachable via a
	 * `relation` object touching it (either endpoint) — the module schema has
	 * no direct applicationService link, so this two-hop lookup mirrors the
	 * relationship-resolution pattern `ViewService`/`ArchiMateService` already
	 * perform for referenceComponent overlays (design.md trade-offs).
	 *
	 * @param array $refCompIds Distinct referenceComponent element identifiers.
	 *
	 * @return array<string,string[]> Referentiecomponent identifier => applicationService names.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 */
	private function resolveApplicatieservicesForReferentiecomponenten(array $refCompIds): array {
		if (empty($refCompIds) === true) {
			return [];
		}

		try {
			$relations = $this->archiMateService->getRelationshipObjects(
				[
					'limit' => self::ELEMENT_LOOKUP_LIMIT,
				]
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'FacetService: failed to resolve relationships for applicationService facet',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}

		$otherEpsByRefComp = $this->collectRelationEndpoints(
			relations: $relations,
			refCompIds: $refCompIds
		);

		if (empty($otherEpsByRefComp) === true) {
			return [];
		}

		$allOtherIds = array_values(array_unique(array_merge(...array_values($otherEpsByRefComp))));
		$elementsById = $this->resolveElementsByIdentifier(identifiers: $allOtherIds);

		return $this->mapEndpointsToApplicatieserviceNames(
			otherEpsByRefComp: $otherEpsByRefComp,
			elementsById: $elementsById
		);

	}//end resolveApplicatieservicesForReferentiecomponenten()

	/**
	 * For every `relation` object, record the OTHER endpoint (source or
	 * target) whenever one side matches a referenceComponent identifier —
	 * relations are undirected for this lookup's purposes (either endpoint
	 * order counts).
	 *
	 * @param array $relations Bounded relation objects.
	 * @param array $refCompIds Distinct referenceComponent element identifiers.
	 *
	 * @return array<string,string[]> Referentiecomponent identifier => other-endpoint identifiers.
	 */
	private function collectRelationEndpoints(array $relations, array $refCompIds): array {
		$refCompLookup = array_flip($refCompIds);
		$otherEpsByRefComp = [];

		foreach ($relations as $relation) {
			$source = $relation['source'] ?? null;
			$target = $relation['target'] ?? null;
			if (is_string($source) === false || is_string($target) === false) {
				continue;
			}

			if (isset($refCompLookup[$source]) === true) {
				$otherEpsByRefComp[$source][] = $target;
			}

			if (isset($refCompLookup[$target]) === true) {
				$otherEpsByRefComp[$target][] = $source;
			}
		}

		return $otherEpsByRefComp;
	}//end collectRelationEndpoints()

	/**
	 * Filter each referenceComponent's other-endpoint identifiers down to
	 * `Applicatieservice`-typed elements and resolve their display names.
	 *
	 * @param array $otherEpsByRefComp Referentiecomponent identifier => other-endpoint identifiers.
	 * @param array $elementsById Resolved element identifier => element object.
	 *
	 * @return array<string,string[]> Referentiecomponent identifier => applicationService names.
	 */
	private function mapEndpointsToApplicatieserviceNames(array $otherEpsByRefComp, array $elementsById): array {
		$result = [];

		foreach ($otherEpsByRefComp as $refCompId => $otherIds) {
			$names = [];
			foreach (array_unique($otherIds) as $otherId) {
				$element = $elementsById[$otherId] ?? null;
				if ($element === null || ($element['gemmaType'] ?? null) !== 'Applicatieservice') {
					continue;
				}

				$names[] = $this->elementDisplayName(element: $element, fallbackIdentifier: $otherId);
			}

			if (empty($names) === false) {
				$result[$refCompId] = array_values(array_unique($names));
			}
		}

		return $result;
	}//end mapEndpointsToApplicatieserviceNames()

	/**
	 * Compute disjunctive facet buckets for every dimension: a dimension's own
	 * counts are computed over the set narrowed by every OTHER selected
	 * dimension (not its own selection) — "self-count is not narrowed by its
	 * own selection" per the spec scenario.
	 *
	 * @param array $dimValsByObjId Object id => dimension => values.
	 * @param array $selectedFilters Normalized selected filters.
	 *
	 * @return array<string,array<int,array{value:string,label:string,count:int}>>
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
	 */
	private function computeFacets(array $dimValsByObjId, array $selectedFilters): array {
		$facets = [];

		foreach (self::DIMENSIONS as $dimension) {
			$narrowedObjectIds = $this->filterObjectIds(
				dimValsByObjId: $dimValsByObjId,
				selectedFilters: $selectedFilters,
				excludeDimension: $dimension
			);

			$counts = [];
			foreach ($narrowedObjectIds as $objectId) {
				$values = $dimValsByObjId[$objectId][$dimension] ?? [];
				foreach ($values as $value) {
					$counts[$value] = ($counts[$value] ?? 0) + 1;
				}
			}

			arsort($counts);

			$bucket = [];
			foreach ($counts as $value => $count) {
				$bucket[] = [
					'value' => $value,
					'label' => $value,
					'count' => $count,
				];
			}

			// Present as an empty array, never omitted.
			$facets[$dimension] = $bucket;
		}//end foreach

		return $facets;
	}//end computeFacets()

	/**
	 * Filter the candidate object ids by the currently selected facet filters:
	 * OR within a dimension, AND across dimensions. `$excludeDimension` skips
	 * applying that one dimension's own filter (used when computing that
	 * dimension's own disjunctive counts).
	 *
	 * @param array $dimValsByObjId Object id => dimension => values.
	 * @param array $selectedFilters Normalized selected filters.
	 * @param string|null $excludeDimension Dimension to skip filtering on, or null.
	 *
	 * @return string[] Matching object ids.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
	 */
	private function filterObjectIds(array $dimValsByObjId, array $selectedFilters, ?string $excludeDimension): array {
		$matched = [];

		foreach ($dimValsByObjId as $objectId => $dimensionValues) {
			$isMatch = true;

			foreach ($selectedFilters as $dimension => $selectedValues) {
				if ($dimension === $excludeDimension) {
					continue;
				}

				$objectValues = $dimensionValues[$dimension] ?? [];
				// OR within a dimension: at least one selected value must be present.
				if (count(array_intersect($selectedValues, $objectValues)) === 0) {
					$isMatch = false;
					break;
				}
			}

			if ($isMatch === true) {
				$matched[] = $objectId;
			}
		}

		return $matched;
	}//end filterObjectIds()

	/**
	 * Extract related-object identifiers from an array/relation field, tolerant
	 * of the several shapes OpenRegister relation fields can carry (mirrors
	 * `ViewService::extractReferentieComponenten()`'s shape-tolerant parsing —
	 * that helper is `private` on ViewService so is re-implemented narrowly
	 * here rather than duplicating ViewService's whole relationship-resolution
	 * surface).
	 *
	 * @param array $object The object carrying the relation field.
	 * @param string $field The relation field name.
	 *
	 * @return string[] Related object identifiers.
	 */
	private function extractRelatedIdentifiers(array $object, string $field): array {
		$value = $object[$field] ?? null;
		if ($value === null) {
			return [];
		}

		if (is_string($value) === true) {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$identifiers = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true) {
				$identifiers[] = $entry;
			} elseif (is_array($entry) === true) {
				$id = $entry['id'] ?? $entry['identifier'] ?? $entry['@self']['id'] ?? null;
				if (is_string($id) === true || is_int($id) === true) {
					$identifiers[] = (string)$id;
				}
			}
		}

		return $identifiers;
	}//end extractRelatedIdentifiers()

	/**
	 * Extract related-object display names (falling back to the identifier)
	 * from an array/relation field carrying inline related-object data.
	 *
	 * @param array $object The object carrying the relation field.
	 * @param string $field The relation field name.
	 *
	 * @return string[] Related object display names.
	 */
	private function extractRelatedNames(array $object, string $field): array {
		$value = $object[$field] ?? null;
		if (is_array($value) === false) {
			return [];
		}

		$names = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true) {
				$names[] = $entry;
			} elseif (is_array($entry) === true) {
				$name = $entry['name'] ?? $entry['title'] ?? $entry['id'] ?? $entry['identifier'] ?? null;
				if (is_string($name) === true) {
					$names[] = $name;
				}
			}
		}

		return $names;
	}//end extractRelatedNames()

	/**
	 * Resolve an element's display name, falling back to its identifier.
	 *
	 * @param array $element The element object.
	 * @param string $fallbackIdentifier Fallback when `name` is missing/blank.
	 *
	 * @return string The display name.
	 */
	private function elementDisplayName(array $element, string $fallbackIdentifier): string {
		$name = $element['name'] ?? null;
		if (is_string($name) === true && trim($name) !== '') {
			return trim($name);
		}

		return $fallbackIdentifier;
	}//end elementDisplayName()

	/**
	 * Resolve a stable identifier for an OpenRegister object (id, falling back
	 * to identifier/uuid shapes).
	 *
	 * @param array $object The object.
	 *
	 * @return string The identifier.
	 */
	private function objectIdentifier(array $object): string {
		$id = $object['id'] ?? $object['@self']['id'] ?? $object['identifier'] ?? $object['uuid'] ?? null;
		if ($id === null) {
			// Extremely defensive fallback — should not happen for real OR objects.
			$id = md5(json_encode($object));
		}

		return (string)$id;
	}//end objectIdentifier()

	/**
	 * Get the current user's active organisation UUID, mirroring
	 * `ViewService::getCurrentOrganisation()`'s OpenRegister OrganisationService lookup.
	 *
	 * @return string|null Current organisation UUID or null if not available.
	 */
	private function getCurrentOrganisation(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$activeOrg = $organisationService->getActiveOrganisation();
			if ($activeOrg !== null) {
				return $activeOrg->getUuid();
			}

			return null;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'FacetService: failed to get current organisation from OpenRegister',
				context: ['error' => $e->getMessage()]
			);
			return null;
		}

	}//end getCurrentOrganisation()

	/**
	 * Get ObjectService from the container.
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null if not available.
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			return $this->container->get(ObjectService::class);
		} catch (\Exception $e) {
			$this->logger->error(
				message: 'FacetService: failed to get ObjectService',
				context: ['error' => $e->getMessage()]
			);
			return null;
		}

	}//end getObjectService()
}//end class
