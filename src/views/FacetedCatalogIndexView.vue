<!--
FacetedCatalogIndexView.vue

GEMMA-dimension faceted index page for the `module` (Applications) and
`dienst` (Services) listings (gemma-faceted-search). Reusable for both
schemas via the `schema` prop — the manifest wires one page per schema.

Layout: a `CnFacetSidebar` (from `@conduction/nextcloud-vue`, ADR-012) driven
by the `facets` Pinia store on the left, and a self-fetching `CnIndexPage`
(register+schema self-fetch mode) on the right.

Why the facet sidebar is NOT `CnIndexPage`'s own embedded
`sidebar.enabled`/`activeFilters` facet machinery: that machinery treats
every active-filter key as a LITERAL, directly-filterable schema field and
applies it straight to the object-list fetch (`useListView`'s
`onFilterChange` → `activeFilters.value[key] = values`, merged verbatim into
the OpenRegister query). Two of the four GEMMA dimensions (`domain`,
`applicationService`) are not module/dienst fields at all — they only exist
on the LINKED `element` object — and the other two are exposed here by
display NAME, not the `referentieComponenten`/`standaardVersies` identifiers
the schema actually stores. Feeding GEMMA facet keys through that path would
apply an incorrect direct-field filter (near-guaranteed empty results)
alongside this feature's own narrowing. This view instead narrows the
self-fetch list via the bounded, RBAC-scoped `{ id: matchedObjectIds }` list
`FacetService` already computes (`_meta.matchedObjectIds` — see
`lib/Service/FacetService.php::computeFacetsForRequest()`), and keeps GEMMA
facet state in the URL under `_gf_`-prefixed query keys so CnIndexPage's own
generic route-query-to-filter passthrough never sees it (see the
`ROUTE_QUERY_PREFIX` docblock in `src/store/modules/facets.js`).

@spec openspec/changes/gemma-faceted-search/tasks.md#task-11
@spec openspec/changes/gemma-faceted-search/tasks.md#task-12
@spec openspec/changes/gemma-faceted-search/tasks.md#task-13
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
@spec openspec/specs/bio-compliance-assessment/spec.md#requirement-catalog-can-be-filtered-by-bbn-level-and-dpia-status
-->

<template>
	<div class="faceted-catalog-index">
		<div class="faceted-catalog-index__header">
			<h2 class="faceted-catalog-index__title">
				{{ title }}
			</h2>
			<p v-if="description" class="faceted-catalog-index__description">
				{{ description }}
			</p>
		</div>

		<div class="faceted-catalog-index__toolbar">
			<NcTextField
				:modelValue="searchValue"
				class="faceted-catalog-index__search"
				:label="t('stackiq', 'Search')"
				:placeholder="t('stackiq', 'Search applications and services…')"
				@update:modelValue="onSearchInput" />
			<NcButton
				v-if="searchValue !== ''"
				variant="tertiary"
				:aria-label="t('stackiq', 'Clear search')"
				@click="onSearchInput('')">
				<template #icon>
					<CloseIcon :size="18" />
				</template>
			</NcButton>

			<NcActions :aria-label="t('stackiq', 'Saved views')">
				<template #icon>
					<FolderStarOutline :size="20" />
				</template>
				<NcActionButton
					:disabled="!facetStore.hasActiveFilterOrSearchFor(schema)"
					@click="showSaveViewModal = true">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
					{{ t('stackiq', 'Save current filters as view') }}
				</NcActionButton>
				<NcActionCaption
					v-if="facetStore[schema].savedViews.length > 0"
					:name="t('stackiq', 'Saved views')" />
				<NcActionButton
					v-for="view in facetStore[schema].savedViews"
					:key="view.id"
					@click="applySavedView(view)">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ view.name }}
				</NcActionButton>
			</NcActions>
		</div>

		<div class="faceted-catalog-index__body">
			<CnFacetSidebar
				:title="t('stackiq', 'GEMMA filters')"
				:schema="facetDimensionSchema"
				:facetData="facetStore.facetDataFor(schema)"
				:activeFilters="facetStore[schema].activeFilters"
				:loading="facetStore[schema].loading"
				:clearLabel="t('stackiq', 'Clear all')"
				class="faceted-catalog-index__sidebar"
				@filterChange="onFacetFilterChange"
				@clearAll="onClearAllFacets" />

			<div class="faceted-catalog-index__list">
				<CnIndexPage
					:key="indexPageKey"
					:title="title"
					:register="register"
					:schema="schema"
					:columns="columns"
					:filter="listFilter"
					:quickFilters="quickFilters"
					:quickFilterMode="quickFilterMode"
					:quickFilterMultiple="quickFilterMultiple" />
			</div>
		</div>

		<SaveFacetViewModal
			:show="showSaveViewModal"
			:saving="savingView"
			@close="showSaveViewModal = false"
			@save="onSaveView" />
	</div>
</template>

<script>
import { CnFacetSidebar, CnIndexPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcActionButton,
	NcActionCaption,
	NcActions,
	NcButton,
	NcTextField,
} from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderStarOutline from 'vue-material-design-icons/FolderStarOutline.vue'
import SaveFacetViewModal from '../modals/SaveFacetViewModal.vue'
import { useFacetStore } from '../store/modules/facets.js'
import { buildFacetDimensionSchema } from '../utils/facetSchema.js'

/** Dimension key -> translated label, matching `FacetController`'s query params. */
const DIMENSION_LABELS = {
	referenceComponent: () => t('stackiq', 'Reference component'),
	standard: () => t('stackiq', 'Standard'),
	applicationService: () => t('stackiq', 'Application service'),
	domain: () => t('stackiq', 'Domain'),
}

/** Debounce delay (ms) between search keystrokes and the facets/list refetch. */
const SEARCH_DEBOUNCE_MS = 400

export default {
	name: 'FacetedCatalogIndexView',

	components: {
		NcTextField,
		NcButton,
		NcActions,
		NcActionButton,
		NcActionCaption,
		CnFacetSidebar,
		CnIndexPage,
		SaveFacetViewModal,
		CloseIcon,
		ContentSaveOutline,
		FolderOutline,
		FolderStarOutline,
	},

	props: {
		/** `module` or `dienst` — which GEMMA-faceted listing this page renders. */
		schema: {
			type: String,
			required: true,
			validator: (value) => ['module', 'catalogService'].includes(value),
		},

		/** OpenRegister register id/slug (resolved from the manifest's `@resolve:voorzieningen_register`). */
		register: {
			type: [String, Number],
			required: true,
		},

		/** Page title (manifest `pages[].title`). */
		title: {
			type: String,
			default: '',
		},

		/** Optional page description. */
		description: {
			type: String,
			default: '',
		},

		/** Table column definitions, forwarded to `CnIndexPage` (manifest `config.columns`). */
		columns: {
			type: Array,
			default: () => [],
		},

		/**
		 * Clickable filter-tab strip, forwarded to `CnIndexPage` (manifest
		 * `config.quickFilters`) — e.g. the BIO BBN-level / DPIA-status quick
		 * filters on the Modules page (bio-compliance-assessment). Composes
		 * with the GEMMA facet narrowing below: `CnIndexPage`'s self-fetch
		 * merges `{ ...base(filter prop), ...activeTab.filter }` (see
		 * `useSelfFetchList.js#fixedFilters`), and this view's own `filter`
		 * prop only ever carries the single `id` key
		 * (`{ id: matchedObjectIds }`, see `listFilter` below). A quick
		 * filter's keys (`bbnLevel`, `dpiaStatus`, …) never collide with
		 * `id`, so the merge is additive — both the facet-matched id set AND
		 * the active quick filter's field constraints apply together
		 * (logical AND), never clobbering one another.
		 */
		quickFilters: {
			type: Array,
			default: null,
		},

		/** Quick-filter render mode, forwarded to `CnIndexPage` (manifest `config.quickFilterMode`). */
		quickFilterMode: {
			type: String,
			default: 'chips',
		},

		/** Allow several quick filters active at once, forwarded to `CnIndexPage` (manifest `config.quickFilterMultiple`). */
		quickFilterMultiple: {
			type: Boolean,
			default: false,
		},
	},

	/**
	 * Expose the facet Pinia store to the options API.
	 *
	 * @return {{facetStore: object}} The store bindings.
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
	 */
	setup() {
		const facetStore = useFacetStore()
		return { facetStore }
	},

	data() {
		return {
			showSaveViewModal: false,
			savingView: false,
			searchDebounceTimer: null,
		}
	},

	computed: {
		/**
		 * `CnFacetSidebar`'s `schema` prop — a schema-shaped document whose
		 * facetable properties are the four GEMMA dimensions.
		 *
		 * ⚠️ This USED to be a `filters` prop carrying the already-derived
		 * filter list. `CnFacetSidebar` declares no `filters` prop: its props
		 * are `schema`, `facetData`, `activeFilters`, `loading`, `title`,
		 * `clearLabel`, `userIsAdmin`, and it derives its filter list itself
		 * via `effectiveFilters() => filtersFromSchema(this.schema)`. Vue drops
		 * an undeclared prop into `$attrs` silently, so the four dimensions
		 * were passed, discarded, and `filtersFromSchema(null)` returned `[]` —
		 * the sidebar rendered its title and an empty body, and no console
		 * error was logged. Verified against the shipped
		 * `@conduction/nextcloud-vue` dist, not only its `src/`.
		 *
		 * `filtersFromSchema` keeps only properties with `facetable: true`,
		 * orders them by `order`, labels them from `title`, and (absent an
		 * `enum`) makes each a `select` whose options come from live
		 * `facetData` — which is exactly what this feature supplies.
		 *
		 * @return {object} A schema document with the four facetable dimensions.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
		 */
		facetDimensionSchema() {
			return buildFacetDimensionSchema(DIMENSION_LABELS)
		},

		/**
		 * @return {string} The current free-text search term for this schema.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
		 */
		searchValue() {
			return this.facetStore[this.schema].search
		},

		/**
		 * Bounded `{ id: [...] }` narrowing filter for `CnIndexPage`'s
		 * self-fetch object list, sourced from the last facets response's
		 * `_meta.matchedObjectIds`. Empty object (no narrowing) when no facet
		 * filter or search term is active.
		 *
		 * @return {object} The `CnIndexPage` `filter` prop value.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
		 */
		listFilter() {
			if (!this.facetStore.hasActiveFilterOrSearchFor(this.schema)) {
				return {}
			}

			const ids = this.facetStore.matchedObjectIdsFor(this.schema)
			// A real (if unlikely) id can never collide with this sentinel —
			// forces a correct EMPTY list rather than `CnIndexPage` treating
			// an empty `id` array as "no filter" (showing everything).
			return { id: ids.length > 0 ? ids : ['__gemma_facet_no_match__'] }
		},

		/**
		 * Remount key for `CnIndexPage`: its self-fetch mode has no reactive
		 * watcher on the `filter` prop (only route/quick-filter/tab changes
		 * trigger an internal refetch), so this view forces a clean remount —
		 * and therefore a fresh `onMounted()` fetch with the current
		 * `listFilter` — whenever the narrowing id set changes.
		 *
		 * @return {string} A key that changes exactly when `listFilter` does.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
		 */
		indexPageKey() {
			return `${this.schema}-${JSON.stringify(this.listFilter)}`
		},
	},

	created() {
		this.facetStore.setFiltersFromQuery(this.schema, this.$route.query)
	},

	/**
	 * Fetch the initial facet counts and this schema's saved views.
	 *
	 * @return {void}
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
	 */
	mounted() {
		this.refetch()
		this.facetStore.fetchSavedViews(this.schema)
	},

	beforeUnmount() {
		clearTimeout(this.searchDebounceTimer)
	},

	methods: {
		/**
		 * Re-fetch this schema's facet counts (search + active filters
		 * already live in the store).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
		 */
		async refetch() {
			await this.facetStore.fetchFacets(this.schema)
		},

		/**
		 * Push the current filter+search state to the URL as `_gf_`-prefixed
		 * query params, preserving any other query params already present.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		syncUrl() {
			const currentQuery = { ...this.$route.query }
			Object.keys(currentQuery).forEach((key) => {
				if (key.startsWith('_gf_')) {
					delete currentQuery[key]
				}
			})

			const nextQuery = {
				...currentQuery,
				...this.facetStore.filtersToQuery(this.schema),
			}
			this.$router.replace({ query: nextQuery }).catch(() => {
				// NavigationDuplicated — same query, no-op.
			})
		},

		/**
		 * `CnFacetSidebar`'s `filter-change` handler: `{ key, values }`.
		 *
		 * @param {{key: string, values: Array}} payload The filter change.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		onFacetFilterChange({ key, values }) {
			this.facetStore.setFilter(this.schema, key, values)
			this.syncUrl()
			this.refetch()
		},

		/**
		 * Clear every active facet filter for this schema and refetch.
		 *
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		onClearAllFacets() {
			this.facetStore.clearFilters(this.schema)
			this.syncUrl()
			this.refetch()
		},

		/**
		 * Debounced search-box handler.
		 *
		 * @param {string} value The new search term.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
		 */
		onSearchInput(value) {
			this.facetStore.setSearch(this.schema, value)
			clearTimeout(this.searchDebounceTimer)
			this.searchDebounceTimer = setTimeout(
				() => {
					this.syncUrl()
					this.refetch()
				},
				value === '' ? 0 : SEARCH_DEBOUNCE_MS,
			)
		},

		/**
		 * Restore a saved view's facet selection + search term.
		 *
		 * @param {object} view The saved view.
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		applySavedView(view) {
			this.facetStore.applyView(this.schema, view)
			this.syncUrl()
			this.refetch()
		},

		/**
		 * Persist the current facet selection + search term as a named view.
		 *
		 * @param {string} name The view name.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		async onSaveView(name) {
			this.savingView = true
			try {
				await this.facetStore.saveCurrentAsView(this.schema, name)
				this.showSaveViewModal = false
				showSuccess(t('stackiq', 'View "{name}" saved', { name }))
			} catch (error) {
				showError(
					t('stackiq', 'Failed to save view: {message}', {
						message: error.message ?? '',
					}),
				)
			} finally {
				this.savingView = false
			}
		},
	},
}
</script>

<style scoped>
.faceted-catalog-index__header {
	padding: calc(var(--default-grid-baseline, 8px) * 2)
		calc(var(--default-grid-baseline, 8px) * 2) 0;
}

.faceted-catalog-index__title {
	margin: 0 0 var(--default-grid-baseline, 8px);
}

.faceted-catalog-index__description {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.faceted-catalog-index__toolbar {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.faceted-catalog-index__search {
	max-width: 400px;
	flex: 1;
}

.faceted-catalog-index__body {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: 0 calc(var(--default-grid-baseline, 8px) * 2)
		calc(var(--default-grid-baseline, 8px) * 2);
}

.faceted-catalog-index__sidebar {
	flex-shrink: 0;
}

.faceted-catalog-index__list {
	flex: 1;
	min-width: 0;
}
</style>
