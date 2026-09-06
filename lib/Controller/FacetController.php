<?php

/**
 * Facet Controller for Stackiq
 *
 * Handles HTTP requests for the GEMMA-dimension facet aggregation endpoint.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
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

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\FacetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the GEMMA-dimension facet aggregation endpoint.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */
class FacetController extends Controller {
	/**
	 * Constructor for FacetController.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param FacetService $facetService The facet aggregation service.
	 * @param LoggerInterface $logger The logger service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FacetService $facetService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Get GEMMA-dimension facet counts for a schema.
	 *
	 * API Endpoint: GET /api/facets/{schema}
	 *
	 * Query Parameters:
	 * - search (string): Free-text query narrowing the candidate set.
	 * - referenceComponent[], standard[], applicationService[], domain[] (string[]):
	 *   Currently-selected facet values per dimension.
	 * - organization (string): Optional organisation override.
	 *
	 * Facet aggregation is a read operation available to any authenticated
	 * catalog user — RBAC scoping happens inside FacetService (identical
	 * posture to ViewController), not at this boundary.
	 *
	 * @param string $schema The facet schema (`module` or `dienst`).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with the per-dimension facet buckets.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
	 */
	public function getFacets(string $schema): JSONResponse {
		$filters = $this->parseFilters();
		$search = $this->request->getParam('search');
		if (is_string($search) === false) {
			$search = null;
		}

		$organization = $this->request->getParam('organization');
		if (is_string($organization) === false) {
			$organization = null;
		}

		try {
			$result = $this->facetService->getFacets(
				schema: $schema,
				filters: $filters,
				search: $search,
				organization: $organization
			);

			return new JSONResponse($result, 200);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(
				[
					'message' => $e->getMessage(),
					'supportedSchemas' => ['module', 'catalogService'],
				],
				400
			);
		} catch (\RuntimeException $e) {
			$this->logger->error(
				message: 'FacetController: ObjectService unavailable',
				context: ['schema' => $schema, 'error' => $e->getMessage()]
			);

			return new JSONResponse(
				['message' => 'Facet aggregation is temporarily unavailable: ' . $e->getMessage()],
				503
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: 'FacetController: failed to compute facets',
				context: [
					'schema' => $schema,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				['message' => 'Internal server error: ' . $e->getMessage()],
				500
			);
		}//end try

	}//end getFacets()

	/**
	 * Parse the per-dimension facet filter query parameters into
	 * `dimension => string[]`.
	 *
	 * @return array<string,array> Raw filters keyed by dimension.
	 */
	private function parseFilters(): array {
		$dimensions = ['referenceComponent', 'standard', 'applicationService', 'domain'];
		$filters = [];

		foreach ($dimensions as $dimension) {
			$value = $this->request->getParam($dimension);
			if ($value === null) {
				continue;
			}

			$filters[$dimension] = [$value];
			if (is_array($value) === true) {
				$filters[$dimension] = $value;
			}
		}

		return $filters;
	}//end parseFilters()
}//end class
