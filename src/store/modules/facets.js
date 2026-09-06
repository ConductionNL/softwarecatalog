/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Pinia store for GEMMA-dimension facet state (gemma-faceted-search).
 *
 * Holds, per schema (`module` / `catalogService`): the active facet selection, the
 * free-text search term, and the last-fetched facet counts. Also owns the
 * URL query <-> filter-state round-trip (deep-linkable filter state) and the
 * saved-view state extraction/restoration used by the "save as view" flow.
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-10
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	FACET_DIMENSIONS,
	fetchFacets as fetchFacetsFromApi,
} from '../../services/facets.js'

/**
 * Route-query key prefix for GEMMA facet state. Deliberately DIFFERENT from
 * the bare dimension names (`referenceComponent`, `standard`, …) used on
 * the wire to `FacetController` — CnIndexPage's self-fetch mode reads EVERY
 * non-underscore-prefixed `$route.query` key as a literal object-list filter
 * (`useSelfFetchList.resolveQueryFilters()`), and the `module`/`catalogService`
 * schema has no field named `referenceComponent`/`standard`/`domain`/
 * `applicationService` (the real fields are `referentieComponenten`,
 * `standaardVersies`, … — see design.md). Letting the bare dimension name
 * leak into `$route.query` would make CnIndexPage attempt an incorrect
 * direct-field filter (near-guaranteed zero results) IN ADDITION to this
 * feature's own `{ id: matchedObjectIds }` narrowing. The `_gf_` prefix
 * keeps GEMMA facet state in the URL (deep-linkable, per spec) while staying
 * invisible to that generic passthrough (`_`-prefixed keys are reserved/
 * skipped there).
 */
const ROUTE_QUERY_PREFIX = '_gf_'

/** Route-query key for the free-text search term. */
const ROUTE_QUERY_SEARCH_KEY = `${ROUTE_QUERY_PREFIX}search`

/**
 * OpenRegister's generic saved-search Views API — the same endpoint
 * CnIndexPage's own built-in "Save as view" affordance uses
 * (`useSavedViewsApi` in `@conduction/nextcloud-vue`, not exported from the
 * package's public barrel). Reused directly here rather than depending on
 * that internal composable, and rather than introducing a new
 * ViewController/ViewService endpoint (task 13's explicit constraint).
 * stackiq's OWN `ViewController`/`ViewService` (`dashboard-views-api`)
 * is a different, read-only ArchiMate-views API (`getAllViews`/`getView`,
 * no create/save) — NOT a saved-filter-view API — so it cannot serve this
 * requirement despite spec.md naming it; this is a documented, deliberate
 * substitution. See gemma-faceted-search's final build report.
 */
const OR_VIEWS_API_BASE = '/apps/openregister/api/views'

/**
 * Marker stored in a saved view's `query` blob identifying it as a GEMMA
 * facet-selection view for this feature (distinguishes it from the many
 * OTHER saved views the same global OR endpoint stores for other index
 * pages/apps).
 *
 * FROZEN at `stackiq-…` through the app-id rename. This string is not
 * a name we read from our own config — it is PERSISTED inside the `query` blob
 * of every saved view already stored in OpenRegister's GLOBAL saved-views
 * store, which this app's repair steps cannot reach. Renaming it makes the
 * marker match nothing: every existing GEMMA facet view stops being recognised
 * as one and silently disappears from the picker, while the rows stay in the
 * database. Nothing errors — an unmatched marker reads exactly like "this user
 * has no saved facet views".
 */
const VIEW_MARKER = 'stackiq-gemma-facets'

/**
 * Build the empty (all-dimensions-present-but-empty) facet response shape,
 * mirroring the backend's contract before the first fetch resolves.
 *
 * @return {object} Empty facet response.
 */
function emptyFacetResponse() {
	const empty = {}
	FACET_DIMENSIONS.forEach((dimension) => {
		empty[dimension] = []
	})
	empty._meta = { totalMatched: 0, processingTimeMs: 0, cached: false }
	return empty
}

/**
 * Build the empty per-schema slice.
 *
 * @return {object} Empty schema slice.
 */
function emptySchemaState() {
	return {
		data: emptyFacetResponse(),
		activeFilters: {},
		search: '',
		loading: false,
		error: null,
		savedViews: [],
		savedViewsLoading: false,
		savedViewsError: null,
	}
}

export const useFacetStore = defineStore('facets', {
	state: () => ({
		module: emptySchemaState(),
		// `catalogService`, the slug this app declares since #958. State is
		// keyed BY SCHEMA, so a bucket named for the old slug is not a stale
		// label: `setFilter('catalogService', …)` writes nowhere and reads back
		// `undefined`, because Pinia state cannot grow a key that was never
		// declared. The failure surfaces as a missing filter, not a bad name.
		catalogService: emptySchemaState(),
	}),

	getters: {
		/**
		 * Live facet data shaped for `CnFacetSidebar`'s `facetData` prop:
		 * `{ dimension: { values: [{ value, count }] } }`.
		 *
		 * @param {object} state Store state.
		 * @return {Function} `(schema) => object`.
		 */
		facetDataFor: (state) => (schema) => {
			const slice = state[schema] ?? emptySchemaState()
			const shaped = {}
			FACET_DIMENSIONS.forEach((dimension) => {
				shaped[dimension] = { values: slice.data[dimension] ?? [] }
			})
			return shaped
		},

		/**
		 * Whether a schema currently has any active facet filter or a
		 * non-blank free-text search term — the gate for narrowing the
		 * object list via `matchedObjectIdsFor` rather than showing the
		 * unfiltered (RBAC-scoped) set.
		 *
		 * @param {object} state Store state.
		 * @return {Function} `(schema) => boolean`.
		 */
		hasActiveFilterOrSearchFor: (state) => (schema) => {
			const slice = state[schema] ?? emptySchemaState()
			const hasFilters = Object.values(slice.activeFilters).some(
				(values) => Array.isArray(values) && values.length > 0,
			)
			return hasFilters || slice.search.trim() !== ''
		},

		/**
		 * The RBAC/filter/search-scoped object id set the last-fetched facet
		 * response describes (`_meta.matchedObjectIds`) — used to narrow the
		 * schema's own object-list query via `{ id: [...] }` (see
		 * `FacetService::computeFacetsForRequest()`'s docblock for why an
		 * id-based filter is used instead of re-deriving one from the facet
		 * selection: `domain`/`applicationService` are not module/catalogService
		 * fields at all, and `referenceComponent`/`standard` values are
		 * display NAMES, not the identifiers the schema actually stores).
		 *
		 * @param {object} state Store state.
		 * @return {Function} `(schema) => string[]`.
		 */
		matchedObjectIdsFor: (state) => (schema) => {
			const slice = state[schema] ?? emptySchemaState()
			return Array.isArray(slice.data?._meta?.matchedObjectIds)
				? slice.data._meta.matchedObjectIds
				: []
		},
	},

	actions: {
		/**
		 * Fetch facet counts for a schema using its current activeFilters/search,
		 * combining free-text search with the active facet selection.
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @param {object} [options] Fetch options.
		 * @param {string} [options.organization] Optional organisation override.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
		 */
		async fetchFacets(schema, { organization } = {}) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			this[schema].loading = true
			this[schema].error = null

			try {
				const data = await fetchFacetsFromApi(schema, {
					filters: this[schema].activeFilters,
					search: this[schema].search,
					organization,
				})
				this[schema].data = data
			} catch (error) {
				this[schema].error = error.message ?? 'Failed to fetch facets'
				// eslint-disable-next-line no-console
				console.error(
					`FacetStore: failed to fetch facets for "${schema}"`,
					error,
				)
			} finally {
				this[schema].loading = false
			}
		},

		/**
		 * Apply a facet selection change (`CnFacetSidebar`'s `@filter-change`
		 * payload shape: `{ key, values }`).
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @param {string} dimension The facet dimension key.
		 * @param {Array|string|null} values The new selection for that dimension.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		setFilter(schema, dimension, values) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			const normalized = Array.isArray(values)
				? values
				: [values].filter(Boolean)
			const nextFilters = { ...this[schema].activeFilters }

			if (normalized.length === 0) {
				delete nextFilters[dimension]
			} else {
				nextFilters[dimension] = normalized
			}

			this[schema].activeFilters = nextFilters
		},

		/**
		 * Set the free-text search term for a schema.
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @param {string} value The search term.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
		 */
		setSearch(schema, value) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			this[schema].search = typeof value === 'string' ? value : ''
		},

		/**
		 * Clear every active facet filter for a schema (search term untouched).
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		clearFilters(schema) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			this[schema].activeFilters = {}
		},

		/**
		 * Restore filter + search state from a parsed `$route.query`-shaped
		 * object (deep link / saved view restoration).
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @param {object} query `$route.query` (or a saved view's stored state).
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		setFiltersFromQuery(schema, query) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			const source = query ?? {}
			const filters = {}

			FACET_DIMENSIONS.forEach((dimension) => {
				const raw = source[`${ROUTE_QUERY_PREFIX}${dimension}`]
				if (raw === undefined || raw === null || raw === '') {
					return
				}
				filters[dimension] = Array.isArray(raw) ? raw : [raw]
			})

			this[schema].activeFilters = filters
			this[schema].search =
				typeof source[ROUTE_QUERY_SEARCH_KEY] === 'string'
					? source[ROUTE_QUERY_SEARCH_KEY]
					: ''
		},

		/**
		 * Serialize the current filter + search state to a `$route.query`-shaped
		 * plain object — the URL-encoded, deep-linkable, saveable filter state.
		 *
		 * Every key carries the `_gf_` prefix (see the module-level docblock on
		 * `ROUTE_QUERY_PREFIX`) so CnIndexPage's self-fetch deep-link passthrough
		 * (which reads every NON-underscore-prefixed `$route.query` key as a
		 * literal object-list filter) never sees — and never mis-applies — a
		 * GEMMA dimension name.
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @return {object} Query object; facet-related keys are OMITTED when unset
		 *                  (clearing all facets removes the query parameters).
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		filtersToQuery(schema) {
			const slice = this[schema] ?? emptySchemaState()
			const query = {}

			FACET_DIMENSIONS.forEach((dimension) => {
				const values = slice.activeFilters[dimension]
				if (Array.isArray(values) && values.length > 0) {
					query[`${ROUTE_QUERY_PREFIX}${dimension}`] = values
				}
			})

			if (slice.search.trim() !== '') {
				query[ROUTE_QUERY_SEARCH_KEY] = slice.search.trim()
			}

			return query
		},

		/**
		 * Fetch the current user's saved GEMMA facet views for a schema —
		 * OpenRegister's generic saved-search Views API
		 * (`GET /apps/openregister/api/views`), filtered client-side to this
		 * feature's own views (`query.marker === VIEW_MARKER`) and this
		 * schema (`query.gemmaSchema === schema`), since that endpoint is
		 * shared across every index page's saved views.
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		async fetchSavedViews(schema) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			this[schema].savedViewsLoading = true
			this[schema].savedViewsError = null

			try {
				const response = await axios.get(generateUrl(OR_VIEWS_API_BASE))
				const results = Array.isArray(response?.data?.results)
					? response.data.results
					: []
				this[schema].savedViews = results.filter(
					(view) =>
						view?.query?.marker === VIEW_MARKER
						&& view?.query?.gemmaSchema === schema,
				)
			} catch (error) {
				this[schema].savedViewsError =
					error.message ?? 'Failed to fetch saved views'
				this[schema].savedViews = []
				// eslint-disable-next-line no-console
				console.error(
					`FacetStore: failed to fetch saved views for "${schema}"`,
					error,
				)
			} finally {
				this[schema].savedViewsLoading = false
			}
		},

		/**
		 * Save the current facet selection + free-text search for a schema as
		 * a named view via the existing OpenRegister Views API — no new
		 * `ViewController`/`ViewService` endpoint is introduced (task 13).
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @param {string} name The view name.
		 * @return {Promise<object>} The created view.
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		async saveCurrentAsView(schema, name) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			const slice = this[schema]
			const payload = {
				name,
				description: '',
				isPublic: false,
				isDefault: false,
				query: {
					marker: VIEW_MARKER,
					gemmaSchema: schema,
					filters: slice.activeFilters,
					search: slice.search,
				},
			}

			const response = await axios.post(
				generateUrl(OR_VIEWS_API_BASE),
				payload,
			)
			const created = response?.data?.view
			if (created) {
				this[schema].savedViews = [...this[schema].savedViews, created]
			}

			return created
		},

		/**
		 * Apply a saved view's stored facet selection + search term to a
		 * schema's active state (does NOT fetch — caller follows up with
		 * `fetchFacets`).
		 *
		 * @param {string} schema `module` or `catalogService`.
		 * @param {object} view The saved view (as returned by `fetchSavedViews`).
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		applyView(schema, view) {
			if (!this[schema]) {
				this[schema] = emptySchemaState()
			}

			const query =
				view
				&& typeof view === 'object'
				&& view.query
				&& typeof view.query === 'object'
					? view.query
					: {}
			const filters =
				query.filters
				&& typeof query.filters === 'object'
				&& !Array.isArray(query.filters)
					? query.filters
					: {}

			const normalizedFilters = {}
			FACET_DIMENSIONS.forEach((dimension) => {
				const values = filters[dimension]
				if (Array.isArray(values) && values.length > 0) {
					normalizedFilters[dimension] = values
				}
			})

			this[schema].activeFilters = normalizedFilters
			this[schema].search =
				typeof query.search === 'string' ? query.search : ''
		},
	},
})
