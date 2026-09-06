<?php

/**
 * Unit tests for FacetService.
 *
 * Covers gemma-faceted-search tasks 1–6: direct-field aggregation, linked
 * `element` resolution (domain/applicationService), narrowing (facet-on-facet
 * AND/OR + disjunctive self-count), free-text search combination, RBAC/tenant
 * scoping parity, and distributed caching + invalidation-key isolation.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal fake mirroring the runtime contract of the REAL
 * `OCA\OpenRegister\Db\ObjectEntity` (not the test-only abstract stub in
 * `tests/Stubs/Db/ObjectEntity.php`, which cannot be instantiated directly):
 * `jsonSerialize()` merges the payload properties with `@self` metadata and
 * mirrors `id` at the top level, exactly as
 * `OCA\OpenRegister\Db\ObjectEntity::jsonSerialize()` does in production.
 *
 * `searchObjectsPaginated()`/`searchObjects()` return real `ObjectEntity`
 * instances in production, never plain arrays — the OLD test double fed
 * `FacetService` plain arrays only, which is exactly how the production 500
 * (`FacetService::objectIdentifier(): Argument #1 ($object) must be of type
 * array, OCA\OpenRegister\Db\ObjectEntity given`) shipped green.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class FakeObjectEntity implements \JsonSerializable {

	/**
	 * @param string $id The object's identifier.
	 * @param array<string,mixed> $payload The object's payload properties (no `id`/`@self`).
	 */
	public function __construct(
		private readonly string $id,
		private readonly array $payload,
	) {
	}

	/**
	 * Mirrors `ObjectEntity::jsonSerialize()`: payload + `@self.id` + top-level `id`.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$data = $this->payload;
		$data['@self'] = ['id' => $this->id];
		$data['id'] = $this->id;

		return $data;
	}//end jsonSerialize()
}//end class

/**
 * Unit tests for FacetService.
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-8
 */
class FacetServiceTest extends TestCase {

	/**
	 * Build a FacetService with the given (mocked) collaborators.
	 *
	 * @param ObjectServiceInterface|null $objectService Mocked ObjectService, or null to omit from container.
	 * @param SettingsService|null $settingsService Mocked SettingsService (defaults to a working voorzieningen config).
	 * @param ArchiMateService|null $archiMateService Mocked ArchiMateService (defaults to empty lookups).
	 * @param ICache|null $cache Mocked ICache (defaults to always-miss/no-op).
	 * @param string|null $userId Simulated current user id, or null for "not logged in".
	 *
	 * @return FacetService
	 */
	private function makeService(
		?ObjectServiceInterface $objectService = null,
		?SettingsService $settingsService = null,
		?ArchiMateService $archiMateService = null,
		?ICache $cache = null,
		?string $userId = 'alice',
	): FacetService {
		// OrganisationService lookup — mocked as a plain object exposing
		// `getActiveOrganisation(): null` (no active organisation by default),
		// since the real interface lives in OpenRegister and isn't a test dependency here.
		$organisationService = new class {
			public function getActiveOrganisation() {
				return null;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $organisationService) {
				if ($id === ObjectService::class) {
					return $objectService;
				}

				return $organisationService;
			}
		);

		if ($settingsService === null) {
			$settingsService = $this->createMock(SettingsService::class);
			$settingsService->method('getVoorzieningenConfig')->willReturn(
				[
					'register' => '10',
					'module_schema' => '20',
					'dienst_schema' => '21',
				]
			);
		}

		if ($archiMateService === null) {
			$archiMateService = $this->createMock(ArchiMateService::class);
			$archiMateService->method('getElementObjects')->willReturn([]);
			$archiMateService->method('getRelationshipObjects')->willReturn([]);
		}

		if ($cache === null) {
			$cache = $this->createMock(ICache::class);
			$cache->method('get')->willReturn(null);
		}

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$userSession = $this->createMock(IUserSession::class);
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return new FacetService(
			container: $container,
			settingsService: $settingsService,
			archiMateService: $archiMateService,
			queryBuilder: new ViewQueryBuilder(),
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
			cacheFactory: $cacheFactory
		);

	}//end makeService()

	/**
	 * Build an ObjectService mock whose `searchObjectsPaginated()` returns the
	 * given results (single page) and records every captured query.
	 *
	 * @param array $results Objects to return.
	 * @param array $capturedRef Reference array; every captured query is appended.
	 *
	 * @return ObjectServiceInterface
	 */
	private function makePaginatedObjectService(array $results, array &$capturedRef): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($results, &$capturedRef): array {
				$capturedRef[] = $query;
				return [
					'results' => $results,
					'total' => count($results),
					'page' => 1,
					'pages' => 1,
				];
			}
		);
		$objectService->method('searchObjects')->willReturn([]);

		return $objectService;
	}//end makePaginatedObjectService()

	/**
	 * Unsupported schema throws InvalidArgumentException naming the supported set.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 *
	 * @return void
	 */
	public function testGetFacetsThrowsForUnsupportedSchema(): void {
		$service = $this->makeService(objectService: $this->createMock(ObjectServiceInterface::class));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/module.*catalogService|catalogService.*module/');
		$service->getFacets(schema: 'catalogContract');

	}//end testGetFacetsThrowsForUnsupportedSchema()

	/**
	 * ObjectService unavailable throws RuntimeException (mapped to 503 by the controller).
	 *
	 * @return void
	 */
	public function testGetFacetsThrowsWhenObjectServiceUnavailable(): void {
		$service = $this->makeService(objectService: null);

		$this->expectException(\RuntimeException::class);
		$service->getFacets(schema: 'module');

	}//end testGetFacetsThrowsWhenObjectServiceUnavailable()

	/**
	 * All four dimensions are always present, even when empty — never omitted.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 *
	 * @return void
	 */
	public function testGetFacetsReturnsAllFourDimensionsEvenWhenEmpty(): void {
		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
		$service = $this->makeService(objectService: $objectService);

		$result = $service->getFacets(schema: 'module');

		foreach (['referenceComponent', 'standard', 'applicationService', 'domain'] as $dimension) {
			$this->assertArrayHasKey($dimension, $result);
			$this->assertSame([], $result[$dimension]);
		}

		$this->assertSame(0, $result['_meta']['totalMatched']);
		$this->assertFalse($result['_meta']['cached']);

	}//end testGetFacetsReturnsAllFourDimensionsEvenWhenEmpty()

	/**
	 * Direct module fields (referentieComponenten / standaardVersies) aggregate
	 * into referenceComponent/standard counts.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testGetFacetsAggregatesDirectModuleFields(): void {
		$modules = [
			[
				'id' => 'm1',
				'referenceComponents' => [['identifier' => 'rc-1']],
				'standardVersions' => [['name' => 'StUF-ZKN']],
			],
			[
				'id' => 'm2',
				'referenceComponents' => [['identifier' => 'rc-1']],
				'standardVersions' => [['name' => 'StUF-ZKN']],
			],
			[
				'id' => 'm3',
				'referenceComponents' => ['rc-2'],
				'standardVersions' => [],
			],
		];

		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn(
			[
				['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent', 'domain' => 'Bedrijfsvoering'],
				['identifier' => 'rc-2', 'name' => 'Klantcontactcomponent', 'domain' => 'Dienstverlening'],
			]
		);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		$result = $service->getFacets(schema: 'module');

		$refCompByValue = array_column($result['referenceComponent'], 'count', 'value');
		$this->assertSame(2, $refCompByValue['Zaakregistratiecomponent']);
		$this->assertSame(1, $refCompByValue['Klantcontactcomponent']);

		$standardByValue = array_column($result['standard'], 'count', 'value');
		$this->assertSame(2, $standardByValue['StUF-ZKN']);

		$domeinByValue = array_column($result['domain'], 'count', 'value');
		$this->assertSame(2, $domeinByValue['Bedrijfsvoering']);
		$this->assertSame(1, $domeinByValue['Dienstverlening']);

		$this->assertSame(3, $result['_meta']['totalMatched']);

	}//end testGetFacetsAggregatesDirectModuleFields()

	/**
	 * REGRESSION: the SAME assertions as
	 * `testGetFacetsAggregatesDirectModuleFields()`, but `searchObjectsPaginated()`
	 * returns `FakeObjectEntity` instances (mirroring the real OpenRegister
	 * `ObjectEntity` contract) instead of plain arrays — this is the shape
	 * production actually hands back. Proves `FacetService` normalizes at
	 * the `fetchBaseObjects()` boundary rather than assuming `array`
	 * (the exact defect that produced the live 500:
	 * `objectIdentifier(): Argument #1 ($object) must be of type array,
	 * OCA\OpenRegister\Db\ObjectEntity given`).
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 *
	 * @return void
	 */
	public function testGetFacetsAggregatesDirectModuleFieldsWhenResultsAreObjectEntityInstances(): void {
		$modules = [
			new FakeObjectEntity(
				id: 'm1',
				payload: [
					'referenceComponents' => [['identifier' => 'rc-1']],
					'standardVersions' => [['name' => 'StUF-ZKN']],
				]
			),
			new FakeObjectEntity(
				id: 'm2',
				payload: [
					'referenceComponents' => [['identifier' => 'rc-1']],
					'standardVersions' => [['name' => 'StUF-ZKN']],
				]
			),
			new FakeObjectEntity(
				id: 'm3',
				payload: [
					'referenceComponents' => ['rc-2'],
					'standardVersions' => [],
				]
			),
		];

		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn(
			[
				['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent', 'domain' => 'Bedrijfsvoering'],
				['identifier' => 'rc-2', 'name' => 'Klantcontactcomponent', 'domain' => 'Dienstverlening'],
			]
		);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		$result = $service->getFacets(schema: 'module');

		$refCompByValue = array_column($result['referenceComponent'], 'count', 'value');
		$this->assertSame(2, $refCompByValue['Zaakregistratiecomponent']);
		$this->assertSame(1, $refCompByValue['Klantcontactcomponent']);

		$standardByValue = array_column($result['standard'], 'count', 'value');
		$this->assertSame(2, $standardByValue['StUF-ZKN']);

		$domeinByValue = array_column($result['domain'], 'count', 'value');
		$this->assertSame(2, $domeinByValue['Bedrijfsvoering']);
		$this->assertSame(1, $domeinByValue['Dienstverlening']);

		$this->assertSame(3, $result['_meta']['totalMatched']);

	}//end testGetFacetsAggregatesDirectModuleFieldsWhenResultsAreObjectEntityInstances()

	/**
	 * `applicationService` is resolved via a `relation` connecting a referenceComponent
	 * element to an element with gemmaType === 'Applicatieservice'.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-2
	 *
	 * @return void
	 */
	public function testGetFacetsResolvesApplicatieserviceViaRelationship(): void {
		$modules = [
			['id' => 'm1', 'referenceComponents' => [['identifier' => 'rc-1']], 'standardVersions' => []],
		];

		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturnCallback(
			function (array $query) {
				$ids = $query['identifier'] ?? [];
				$all = [
					'rc-1' => ['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent', 'domain' => 'Bedrijfsvoering'],
					'as-1' => ['identifier' => 'as-1', 'name' => 'Zaakservice', 'gemmaType' => 'Applicatieservice'],
				];
				return array_values(array_intersect_key($all, array_flip($ids)));
			}
		);
		$archiMateService->method('getRelationshipObjects')->willReturn(
			[
				['source' => 'as-1', 'target' => 'rc-1', 'type' => 'Serving'],
			]
		);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		$result = $service->getFacets(schema: 'module');

		$applicatieserviceByValue = array_column($result['applicationService'], 'count', 'value');
		$this->assertArrayHasKey('Zaakservice', $applicatieserviceByValue);
		$this->assertSame(1, $applicatieserviceByValue['Zaakservice']);

	}//end testGetFacetsResolvesApplicatieserviceViaRelationship()

	/**
	 * Selecting one facet value narrows counts for OTHER dimensions, but a
	 * dimension's own count is NOT narrowed by its own selection
	 * (disjunctive faceting).
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-3
	 *
	 * @return void
	 */
	public function testGetFacetsNarrowingIsDisjunctive(): void {
		$modules = [
			['id' => 'm1', 'referenceComponents' => [['identifier' => 'rc-1']], 'standardVersions' => [['name' => 'StUF-ZKN']]],
			['id' => 'm2', 'referenceComponents' => [['identifier' => 'rc-1']], 'standardVersions' => []],
			['id' => 'm3', 'referenceComponents' => [['identifier' => 'rc-2']], 'standardVersions' => [['name' => 'StUF-ZKN']]],
		];

		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn(
			[
				['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent'],
				['identifier' => 'rc-2', 'name' => 'Klantcontactcomponent'],
			]
		);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		$result = $service->getFacets(
			schema: 'module',
			filters: ['referenceComponent' => ['Zaakregistratiecomponent']]
		);

		// `standard` is narrowed to the 2 modules carrying "Zaakregistratiecomponent" —
		// only m1 of those also carries "StUF-ZKN".
		$standardByValue = array_column($result['standard'], 'count', 'value');
		$this->assertSame(1, $standardByValue['StUF-ZKN']);

		// `referenceComponent`'s OWN count is NOT narrowed by its own selection —
		// it still reflects the full 2-object set carrying "Zaakregistratiecomponent".
		$refCompByValue = array_column($result['referenceComponent'], 'count', 'value');
		$this->assertSame(2, $refCompByValue['Zaakregistratiecomponent']);

		$this->assertSame(2, $result['_meta']['totalMatched']);

	}//end testGetFacetsNarrowingIsDisjunctive()

	/**
	 * Multiple values within one dimension combine with OR; across dimensions with AND.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-3
	 *
	 * @return void
	 */
	public function testGetFacetsOrWithinDimensionAndAcrossDimensions(): void {
		$modules = [
			['id' => 'm1', 'referenceComponents' => [['identifier' => 'rc-1']], 'standardVersions' => [['name' => 'StUF-ZKN']]],
			['id' => 'm2', 'referenceComponents' => [['identifier' => 'rc-2']], 'standardVersions' => []],
			['id' => 'm3', 'referenceComponents' => [['identifier' => 'rc-3']], 'standardVersions' => []],
		];

		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn(
			[
				['identifier' => 'rc-1', 'name' => 'A'],
				['identifier' => 'rc-2', 'name' => 'B'],
				['identifier' => 'rc-3', 'name' => 'C'],
			]
		);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		// OR within a dimension: A or B -> m1 + m2.
		$orResult = $service->getFacets(schema: 'module', filters: ['referenceComponent' => ['A', 'B']]);
		$this->assertSame(2, $orResult['_meta']['totalMatched']);

		// AND across dimensions: A (referenceComponent) AND StUF-ZKN (standard) -> only m1.
		$andResult = $service->getFacets(
			schema: 'module',
			filters: [
				'referenceComponent' => ['A'],
				'standard' => ['StUF-ZKN'],
			]
		);
		$this->assertSame(1, $andResult['_meta']['totalMatched']);

	}//end testGetFacetsOrWithinDimensionAndAcrossDimensions()

	/**
	 * A `search` query parameter is forwarded as `_search` on the base
	 * object query — combining free-text search with facet aggregation.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-4
	 *
	 * @return void
	 */
	public function testGetFacetsForwardsSearchAsUnderscoreSearchParam(): void {
		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
		$service = $this->makeService(objectService: $objectService);

		$service->getFacets(schema: 'module', search: 'zaak');

		$this->assertNotEmpty($captured);
		$this->assertSame('zaak', $captured[0]['_search'] ?? null);

	}//end testGetFacetsForwardsSearchAsUnderscoreSearchParam()

	/**
	 * No search parameter means no `_search` key is added — facets cover the
	 * full RBAC-scoped set.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-4
	 *
	 * @return void
	 */
	public function testGetFacetsOmitsSearchParamWhenNotProvided(): void {
		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
		$service = $this->makeService(objectService: $objectService);

		$service->getFacets(schema: 'module');

		$this->assertArrayNotHasKey('_search', $captured[0]);

	}//end testGetFacetsOmitsSearchParamWhenNotProvided()

	/**
	 * Every base-object query carries an explicit `_limit` — the
	 * bound-unbounded-searchobjects-scans pattern.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-1
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 *
	 * @return void
	 */
	public function testGetFacetsQueriesCarryExplicitLimit(): void {
		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
		$service = $this->makeService(objectService: $objectService);

		$service->getFacets(schema: 'module');

		$this->assertNotEmpty($captured);
		foreach ($captured as $query) {
			$this->assertArrayHasKey('_limit', $query);
			$this->assertGreaterThan(0, $query['_limit']);
		}

	}//end testGetFacetsQueriesCarryExplicitLimit()

	/**
	 * An explicit `organization` override is applied as `@self.organisation`
	 * on the base object query — the same RBAC/tenant-scoping convention
	 * `ViewService` already uses for its own module/gebruik queries.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-5
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
	 *
	 * @return void
	 */
	public function testGetFacetsAppliesOrganisationScoping(): void {
		$captured = [];
		$objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
		$service = $this->makeService(objectService: $objectService);

		$service->getFacets(schema: 'module', organization: 'org-uuid-123');

		$this->assertSame('org-uuid-123', $captured[0]['@self']['organisation'] ?? null);

	}//end testGetFacetsAppliesOrganisationScoping()

	/**
	 * Two different users produce different cache keys — no cross-user/tenant
	 * cache bleed.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-6
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
	 *
	 * @return void
	 */
	public function testCacheKeyDiffersPerUser(): void {
		$serviceAlice = $this->makeService(objectService: $this->createMock(ObjectServiceInterface::class), userId: 'alice');
		$serviceBob = $this->makeService(objectService: $this->createMock(ObjectServiceInterface::class), userId: 'bob');

		$reflectionAlice = new \ReflectionMethod($serviceAlice, 'buildCacheKey');
		$reflectionAlice->setAccessible(true);
		$keyAlice = $reflectionAlice->invoke($serviceAlice, 'module', [], null, null);

		$reflectionBob = new \ReflectionMethod($serviceBob, 'buildCacheKey');
		$reflectionBob->setAccessible(true);
		$keyBob = $reflectionBob->invoke($serviceBob, 'module', [], null, null);

		$this->assertNotSame($keyAlice, $keyBob);

	}//end testCacheKeyDiffersPerUser()

	/**
	 * A cache hit is returned with `_meta.cached: true` and skips recomputation
	 * (the mocked ObjectService is never invoked).
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-6
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
	 *
	 * @return void
	 */
	public function testGetFacetsServesFromCacheOnHit(): void {
		$cachedPayload = [
			'referenceComponent' => [],
			'standard' => [],
			'applicationService' => [],
			'domain' => [],
			'_meta' => ['totalMatched' => 0, 'processingTimeMs' => 5.0, 'cached' => false],
		];

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cachedPayload);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('searchObjectsPaginated');

		$service = $this->makeService(objectService: $objectService, cache: $cache);

		$result = $service->getFacets(schema: 'module');

		$this->assertTrue($result['_meta']['cached']);

	}//end testGetFacetsServesFromCacheOnHit()

	/**
	 * A dienst's facet values resolve transitively via its linked modules.
	 *
	 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testGetFacetsResolvesDienstFacetsTransitivelyViaModules(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$capturedPaginated = [];
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$capturedPaginated): array {
				$capturedPaginated[] = $query;
				return [
					'results' => [
						['id' => 'd1', 'modules' => [['id' => 'm1']]],
					],
					'total' => 1,
					'page' => 1,
					'pages' => 1,
				];
			}
		);
		$objectService->method('searchObjects')->willReturn(
			[
				['id' => 'm1', 'referenceComponents' => [['identifier' => 'rc-1']], 'standardVersions' => []],
			]
		);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn(
			[['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent']]
		);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		$result = $service->getFacets(schema: 'catalogService');

		$refCompByValue = array_column($result['referenceComponent'], 'count', 'value');
		$this->assertSame(1, $refCompByValue['Zaakregistratiecomponent']);

	}//end testGetFacetsResolvesDienstFacetsTransitivelyViaModules()

	/**
	 * REGRESSION: the SAME scenario as
	 * `testGetFacetsResolvesDienstFacetsTransitivelyViaModules()`, but BOTH
	 * OpenRegister boundaries return `FakeObjectEntity` instances instead of
	 * plain arrays — the bounded base-object page (`searchObjectsPaginated()`,
	 * the `dienst` results) AND the batch module lookup
	 * (`searchObjects()`, resolved inside `fetchModulesByIdentifiers()`).
	 * Proves normalization happens at BOTH boundaries `FacetService`
	 * consumes OpenRegister search results from, not just the first one.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 *
	 * @return void
	 */
	public function testGetFacetsResolvesDienstFacetsWhenBothLookupsReturnObjectEntityInstances(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$capturedPaginated = [];
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$capturedPaginated): array {
				$capturedPaginated[] = $query;
				return [
					'results' => [
						new FakeObjectEntity(id: 'd1', payload: ['modules' => [['id' => 'm1']]]),
					],
					'total' => 1,
					'page' => 1,
					'pages' => 1,
				];
			}
		);
		$objectService->method('searchObjects')->willReturn(
			[
				new FakeObjectEntity(
					id: 'm1',
					payload: ['referenceComponents' => [['identifier' => 'rc-1']], 'standardVersions' => []]
				),
			]
		);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn(
			[['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent']]
		);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

		$result = $service->getFacets(schema: 'catalogService');

		$refCompByValue = array_column($result['referenceComponent'], 'count', 'value');
		$this->assertSame(1, $refCompByValue['Zaakregistratiecomponent']);
		$this->assertSame(1, $result['_meta']['totalMatched']);

	}//end testGetFacetsResolvesDienstFacetsWhenBothLookupsReturnObjectEntityInstances()
}//end class
