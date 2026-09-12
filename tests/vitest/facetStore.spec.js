/**
 * Unit tests for the GEMMA facet Pinia store (gemma-faceted-search).
 *
 * MOVED FROM `src/store/modules/facets.spec.js` (jest) TO vitest. pinia 4 is
 * ESM-only — `type: module`, an `exports` map naming `dist/pinia.js`, and no
 * CommonJS build at all — so jest's CJS runtime cannot require it and this
 * suite died in `createRequireEsmError` before a single assertion ran.
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-10
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchFacets } from '../../src/services/facets.js'
import { useFacetStore } from '../../src/store/modules/facets.js'

// The jest original needed `{ virtual: true }` here, because `@nextcloud/axios`
// is ESM-only (an `exports` map with no `require` condition) and jest's CJS
// resolver could not load it even to mock it. vitest resolves ESM natively, so
// the mock is an ordinary one.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path) => path),
}))

// `importActual` is ASYNC where `jest.requireActual` was synchronous, so the
// factory becomes async. Everything else about the partial mock is the same:
// keep the real module and replace one export.
vi.mock('../../src/services/facets.js', async () => {
	const actual = await vi.importActual('../../src/services/facets.js')
	return {
		...actual,
		fetchFacets: vi.fn(),
	}
})

describe('facets store — filter/search state', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('setFilter adds a dimension selection', () => {
		const store = useFacetStore()
		store.setFilter('module', 'referenceComponent', ['Zaakregistratiecomponent'])
		expect(store.module.activeFilters.referenceComponent).toEqual([
			'Zaakregistratiecomponent',
		])
	})

	it('setFilter with an empty array removes the dimension entirely', () => {
		const store = useFacetStore()
		store.setFilter('module', 'referenceComponent', ['A'])
		store.setFilter('module', 'referenceComponent', [])
		expect(store.module.activeFilters).not.toHaveProperty('referenceComponent')
	})

	it('setSearch stores the term; non-string values coerce to empty', () => {
		const store = useFacetStore()
		store.setSearch('module', 'zaak')
		expect(store.module.search).toBe('zaak')
		store.setSearch('module', null)
		expect(store.module.search).toBe('')
	})

	it('clearFilters empties activeFilters but leaves search untouched', () => {
		const store = useFacetStore()
		store.setFilter('module', 'standard', ['StUF-ZKN'])
		store.setSearch('module', 'zaak')
		store.clearFilters('module')
		expect(store.module.activeFilters).toEqual({})
		expect(store.module.search).toBe('zaak')
	})

	it('module and catalogService state are independent', () => {
		const store = useFacetStore()
		store.setFilter('module', 'referenceComponent', ['A'])
		store.setFilter('catalogService', 'referenceComponent', ['B'])
		expect(store.module.activeFilters.referenceComponent).toEqual(['A'])
		expect(store.catalogService.activeFilters.referenceComponent).toEqual(['B'])
	})
})

describe('facets store — hasActiveFilterOrSearchFor / matchedObjectIdsFor', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('is false with no filters and no search', () => {
		const store = useFacetStore()
		expect(store.hasActiveFilterOrSearchFor('module')).toBe(false)
	})

	it('is true once a facet filter is set', () => {
		const store = useFacetStore()
		store.setFilter('module', 'domain', ['Bedrijfsvoering'])
		expect(store.hasActiveFilterOrSearchFor('module')).toBe(true)
	})

	it('is true once a search term is set', () => {
		const store = useFacetStore()
		store.setSearch('module', 'zaak')
		expect(store.hasActiveFilterOrSearchFor('module')).toBe(true)
	})

	it('matchedObjectIdsFor reads _meta.matchedObjectIds from the last fetch', () => {
		const store = useFacetStore()
		store.module.data._meta.matchedObjectIds = ['id-1', 'id-2']
		expect(store.matchedObjectIdsFor('module')).toEqual(['id-1', 'id-2'])
	})

	it('matchedObjectIdsFor defaults to an empty array', () => {
		const store = useFacetStore()
		expect(store.matchedObjectIdsFor('module')).toEqual([])
	})
})

describe('facets store — URL query round-trip (_gf_ prefixed keys)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('filtersToQuery emits _gf_-prefixed keys only for active dimensions', () => {
		const store = useFacetStore()
		store.setFilter('module', 'referenceComponent', ['Zaakregistratiecomponent'])
		store.setSearch('module', 'zaak')

		const query = store.filtersToQuery('module')

		expect(query).toEqual({
			_gf_referenceComponent: ['Zaakregistratiecomponent'],
			_gf_search: 'zaak',
		})
	})

	it('filtersToQuery omits keys when no filters/search are active', () => {
		const store = useFacetStore()
		expect(store.filtersToQuery('module')).toEqual({})
	})

	it('setFiltersFromQuery restores state from a _gf_-prefixed route query', () => {
		const store = useFacetStore()
		store.setFiltersFromQuery('module', {
			_gf_referenceComponent: ['Zaakregistratiecomponent'],
			_gf_standard: ['StUF-ZKN'],
			_gf_search: 'zaak',
			// A bare (unprefixed) key MUST be ignored — it is not this
			// feature's own query param and must never leak into GEMMA state.
			referenceComponent: ['should-be-ignored'],
		})

		expect(store.module.activeFilters).toEqual({
			referenceComponent: ['Zaakregistratiecomponent'],
			standard: ['StUF-ZKN'],
		})
		expect(store.module.search).toBe('zaak')
	})

	it('setFiltersFromQuery degrades to empty state for a null/empty query', () => {
		const store = useFacetStore()
		store.setFiltersFromQuery('module', null)
		expect(store.module.activeFilters).toEqual({})
		expect(store.module.search).toBe('')
	})

	it('round-trips filtersToQuery -> setFiltersFromQuery', () => {
		const store = useFacetStore()
		store.setFilter('catalogService', 'domain', [
			'Bedrijfsvoering',
			'Dienstverlening',
		])
		store.setSearch('catalogService', 'stuf')

		const query = store.filtersToQuery('catalogService')
		store.setFiltersFromQuery('catalogService', query)

		expect(store.catalogService.activeFilters).toEqual({
			domain: ['Bedrijfsvoering', 'Dienstverlening'],
		})
		expect(store.catalogService.search).toBe('stuf')
	})
})

describe('facets store — fetchFacets', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		fetchFacets.mockReset()
	})

	it('populates data on success and clears loading', async () => {
		const payload = {
			referenceComponent: [],
			standard: [],
			applicationService: [],
			domain: [],
			_meta: { totalMatched: 0, matchedObjectIds: [] },
		}
		fetchFacets.mockResolvedValue(payload)

		const store = useFacetStore()
		await store.fetchFacets('module')

		expect(store.module.data).toEqual(payload)
		expect(store.module.loading).toBe(false)
		expect(store.module.error).toBeNull()
	})

	it('passes the schema state (filters/search) through to the API client', async () => {
		fetchFacets.mockResolvedValue({
			referenceComponent: [],
			standard: [],
			applicationService: [],
			domain: [],
			_meta: {},
		})

		const store = useFacetStore()
		store.setFilter('module', 'referenceComponent', ['A'])
		store.setSearch('module', 'zaak')
		await store.fetchFacets('module', { organization: 'org-1' })

		expect(fetchFacets).toHaveBeenCalledWith('module', {
			filters: { referenceComponent: ['A'] },
			search: 'zaak',
			organization: 'org-1',
		})
	})

	it('sets error and leaves loading false on failure', async () => {
		fetchFacets.mockRejectedValue(new Error('boom'))

		const store = useFacetStore()
		await store.fetchFacets('module')

		expect(store.module.error).toBe('boom')
		expect(store.module.loading).toBe(false)
	})
})

describe('facets store — saved views', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it("fetchSavedViews keeps only this feature's marker + matching schema", async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{
						id: 1,
						query: {
							marker: 'stackiq-gemma-facets',
							gemmaSchema: 'module',
						},
					},
					{
						id: 2,
						query: {
							marker: 'stackiq-gemma-facets',
							gemmaSchema: 'service',
						},
					},
					{ id: 3, query: { marker: 'some-other-feature' } },
					{ id: 4, query: null },
				],
			},
		})

		const store = useFacetStore()
		await store.fetchSavedViews('module')

		expect(store.module.savedViews).toEqual([
			{
				id: 1,
				query: {
					marker: 'stackiq-gemma-facets',
					gemmaSchema: 'module',
				},
			},
		])
		expect(store.module.savedViewsLoading).toBe(false)
	})

	it('fetchSavedViews sets an error and empties the list on failure', async () => {
		axios.get.mockRejectedValue(new Error('network down'))

		const store = useFacetStore()
		await store.fetchSavedViews('module')

		expect(store.module.savedViewsError).toBe('network down')
		expect(store.module.savedViews).toEqual([])
	})

	it('saveCurrentAsView posts the marked payload and appends the created view', async () => {
		const created = {
			id: 9,
			name: 'My view',
			query: { marker: 'stackiq-gemma-facets', gemmaSchema: 'module' },
		}
		axios.post.mockResolvedValue({ data: { view: created } })

		const store = useFacetStore()
		store.setFilter('module', 'referenceComponent', ['A'])
		store.setSearch('module', 'zaak')

		const result = await store.saveCurrentAsView('module', 'My view')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/views',
			expect.objectContaining({
				name: 'My view',
				query: expect.objectContaining({
					marker: 'stackiq-gemma-facets',
					gemmaSchema: 'module',
					filters: { referenceComponent: ['A'] },
					search: 'zaak',
				}),
			}),
		)
		expect(result).toEqual(created)
		expect(store.module.savedViews).toEqual([created])
	})

	it('applyView restores filters/search from a saved view', () => {
		const store = useFacetStore()
		store.applyView('module', {
			query: {
				filters: { referenceComponent: ['Zaakregistratiecomponent'] },
				search: 'zaak',
			},
		})

		expect(store.module.activeFilters).toEqual({
			referenceComponent: ['Zaakregistratiecomponent'],
		})
		expect(store.module.search).toBe('zaak')
	})

	it('applyView degrades to empty state for a malformed view', () => {
		const store = useFacetStore()
		store.applyView('module', {})
		expect(store.module.activeFilters).toEqual({})
		expect(store.module.search).toBe('')
	})
})
