<?php

/**
 * Unit tests for FacetController's status-code mapping and filter parsing.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\FacetController;
use OCA\Stackiq\Service\FacetService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FacetController.
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-7
 */
class FacetControllerTest extends TestCase {

	/**
	 * A supported schema returns 200 with the service's payload.
	 *
	 * @return void
	 */
	public function testGetFacetsReturns200OnSuccess(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);

		$facetService = $this->createMock(FacetService::class);
		$facetService->method('getFacets')->willReturn(
			[
				'referenceComponent' => [],
				'standard' => [],
				'applicationService' => [],
				'domain' => [],
				'_meta' => ['totalMatched' => 0, 'processingTimeMs' => 1.0, 'cached' => false],
			]
		);

		$controller = new FacetController(
			appName: 'stackiq',
			request: $request,
			facetService: $facetService,
			logger: $this->createMock(LoggerInterface::class)
		);

		$response = $controller->getFacets('module');

		$this->assertSame(200, $response->getStatus());

	}//end testGetFacetsReturns200OnSuccess()

	/**
	 * An unsupported schema (service throws InvalidArgumentException) maps to 400
	 * with an error naming the supported schemas.
	 *
	 * @return void
	 */
	public function testGetFacetsReturns400ForUnsupportedSchema(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);

		$facetService = $this->createMock(FacetService::class);
		$facetService->method('getFacets')->willThrowException(
			new \InvalidArgumentException('Unsupported facet schema "catalogContract". Supported schemas: module, dienst.')
		);

		$controller = new FacetController(
			appName: 'stackiq',
			request: $request,
			facetService: $facetService,
			logger: $this->createMock(LoggerInterface::class)
		);

		$response = $controller->getFacets('catalogContract');

		$this->assertSame(400, $response->getStatus());
		$data = $response->getData();
		$this->assertContains('module', $data['supportedSchemas']);
		$this->assertContains('catalogService', $data['supportedSchemas']);

	}//end testGetFacetsReturns400ForUnsupportedSchema()

	/**
	 * ObjectService unavailable (service throws RuntimeException) maps to 503
	 * with a logged, descriptive error.
	 *
	 * @return void
	 */
	public function testGetFacetsReturns503WhenObjectServiceUnavailable(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);

		$facetService = $this->createMock(FacetService::class);
		$facetService->method('getFacets')->willThrowException(
			new \RuntimeException('OpenRegister ObjectService not available')
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$controller = new FacetController(
			appName: 'stackiq',
			request: $request,
			facetService: $facetService,
			logger: $logger
		);

		$response = $controller->getFacets('module');

		$this->assertSame(503, $response->getStatus());

	}//end testGetFacetsReturns503WhenObjectServiceUnavailable()

	/**
	 * Any other exception maps to 500 with a logged, descriptive error.
	 *
	 * @return void
	 */
	public function testGetFacetsReturns500OnUnexpectedException(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);

		$facetService = $this->createMock(FacetService::class);
		$facetService->method('getFacets')->willThrowException(new \Exception('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$controller = new FacetController(
			appName: 'stackiq',
			request: $request,
			facetService: $facetService,
			logger: $logger
		);

		$response = $controller->getFacets('module');

		$this->assertSame(500, $response->getStatus());

	}//end testGetFacetsReturns500OnUnexpectedException()

	/**
	 * Array-shaped facet query parameters (`referenceComponent[]=A&referenceComponent[]=B`)
	 * are forwarded to `FacetService::getFacets()` as filters.
	 *
	 * @return void
	 */
	public function testGetFacetsForwardsArrayFilterParams(): void {
		$paramMap = [
			'referenceComponent' => ['A', 'B'],
			'standard' => null,
			'applicationService' => null,
			'domain' => null,
			'search' => 'zaak',
			'organization' => null,
		];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key) => $paramMap[$key] ?? null
		);

		$capturedFilters = null;
		$capturedSearch = null;

		$facetService = $this->createMock(FacetService::class);
		$facetService->method('getFacets')->willReturnCallback(
			function (string $schema, array $filters = [], ?string $search = null, ?string $organization = null) use (&$capturedFilters, &$capturedSearch): array {
				$capturedFilters = $filters;
				$capturedSearch = $search;
				return [
					'referenceComponent' => [],
					'standard' => [],
					'applicationService' => [],
					'domain' => [],
					'_meta' => ['totalMatched' => 0, 'processingTimeMs' => 1.0, 'cached' => false],
				];
			}
		);

		$controller = new FacetController(
			appName: 'stackiq',
			request: $request,
			facetService: $facetService,
			logger: $this->createMock(LoggerInterface::class)
		);

		$controller->getFacets('module');

		$this->assertSame(['A', 'B'], $capturedFilters['referenceComponent']);
		$this->assertSame('zaak', $capturedSearch);

	}//end testGetFacetsForwardsArrayFilterParams()
}//end class
