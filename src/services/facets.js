/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * facets.js — API client for the GEMMA-dimension facet aggregation endpoint.
 *
 * Mirrors the `view-enrichment-api` fetch pattern (`src/store/modules/view.js`):
 * a plain axios GET against a `generateUrl()`-built URL, with query params
 * built from the caller's schema/filters/search/organization state.
 *
 * @module Services/facets
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-10
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** GEMMA facet dimensions this client supports, in display order. */
export const FACET_DIMENSIONS = [
	// WIRE NAMES, and they must stay as a SET. FacetController and FacetService
	// both declare ['referenceComponent', 'standard', 'applicationService',
	// 'domain'] — these strings are the query parameters and the response keys of
	// this app's own facet endpoint. They were Dutch until the vocabulary pass
	// moved all four together; an earlier tranche translated exactly ONE of
	// them, so the frontend sent `standard[]` to a backend still reading
	// `standaard[]` and facet filtering by standard silently returned
	// everything, with no error on either side. That is why they move as a SET.
	'referenceComponent',
	'standard',
	'applicationService',
	'domain',
]

/**
 * Build the URLSearchParams for a facet request, repeating array-valued
 * filters as `dimension[]=value` (matches the backend's `FacetController::parseFilters()`
 * convention and the spec's documented query shape).
 *
 * @param {object} options Request options.
 * @param {object} [options.filters] Selected facet values keyed by dimension: `{ referenceComponent: ['A', 'B'] }`.
 * @param {string} [options.search] Free-text query.
 * @param {string} [options.organization] Organisation override.
 * @return {URLSearchParams} The query parameters.
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */
export function buildFacetQueryParams({
	filters = {},
	search = '',
	organization = '',
} = {}) {
	const params = new URLSearchParams()

	FACET_DIMENSIONS.forEach((dimension) => {
		const values = filters[dimension]
		if (!Array.isArray(values)) {
			return
		}
		values
			.filter((value) => typeof value === 'string' && value.trim() !== '')
			.forEach((value) => {
				params.append(`${dimension}[]`, value)
			})
	})

	if (typeof search === 'string' && search.trim() !== '') {
		params.set('search', search.trim())
	}

	if (typeof organization === 'string' && organization.trim() !== '') {
		params.set('organization', organization.trim())
	}

	return params
}

/**
 * Fetch GEMMA-dimension facet counts for a schema.
 *
 * @param {string} schema `module` or `catalogService`.
 * @param {object} [options] See `buildFacetQueryParams()`.
 * @return {Promise<object>} The facet response: `{ referenceComponent, standard, applicationService, domain, _meta }`.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */
export async function fetchFacets(schema, options = {}) {
	const params = buildFacetQueryParams(options)
	const query = params.toString()
	const url =
		generateUrl(`/apps/stackiq/api/facets/${encodeURIComponent(schema)}`)
		+ (query !== '' ? `?${query}` : '')

	const response = await axios.get(url)
	return response.data
}
