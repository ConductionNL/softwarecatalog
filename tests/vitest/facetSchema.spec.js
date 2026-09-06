/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contract test for the GEMMA facet sidebar's schema document.
 *
 * The bug this pins: `FacetedCatalogIndexView` passed `:filters` to
 * `CnFacetSidebar`, which declares no such prop. Vue drops an undeclared prop
 * into `$attrs` silently, so the four GEMMA dimensions were discarded and
 * `filtersFromSchema(null)` returned `[]` — the sidebar rendered its title and
 * an empty body, with no console error and no failing test.
 *
 * ⚠️ These assertions run against the REAL `filtersFromSchema` loaded from the
 * installed `@conduction/nextcloud-vue`, not a local restatement of its rules.
 * A local copy of a dependency's rules is only as fresh as its last manual
 * edit and fails in both directions — the same reason the widget-icon spec
 * reads the package's own registry.
 */

import { describe, expect, it } from 'vitest'
// The REAL implementation from the installed package — not a local restatement.
import { filtersFromSchema } from '../../node_modules/@conduction/nextcloud-vue/src/utils/schema.js'
import { buildFacetDimensionSchema } from '../../src/utils/facetSchema.js'

/** The four GEMMA dimensions FacetedCatalogIndexView declares. */
const DIMENSION_LABELS = {
	referenceComponent: () => 'Reference component',
	standard: () => 'Standard',
	applicationService: () => 'Application service',
	domain: () => 'Domain',
}

describe('buildFacetDimensionSchema', () => {
	it('produces a document the real filtersFromSchema turns into one filter per dimension', () => {
		const filters = filtersFromSchema(
			buildFacetDimensionSchema(DIMENSION_LABELS),
		)

		expect(filters).toHaveLength(4)
		expect(filters.map((f) => f.key)).toEqual([
			'referenceComponent',
			'standard',
			'applicationService',
			'domain',
		])
	})

	it('labels every filter from the dimension title, never from the raw key', () => {
		const filters = filtersFromSchema(
			buildFacetDimensionSchema(DIMENSION_LABELS),
		)

		expect(filters.map((f) => f.label)).toEqual([
			'Reference component',
			'Standard',
			'Application service',
			'Domain',
		])
	})

	it('makes every dimension a select, so live facet counts become its options', () => {
		const filters = filtersFromSchema(
			buildFacetDimensionSchema(DIMENSION_LABELS),
		)

		expect(filters.every((f) => f.type === 'select')).toBe(true)
	})

	it('drops every dimension if facetable is not set — the failure mode being guarded', () => {
		// Reproduce the pre-fix shape: the already-derived filter LIST, which
		// carries no `properties` key at all.
		const derivedListShape = Object.keys(DIMENSION_LABELS).map((key) => ({
			key,
			label: DIMENSION_LABELS[key](),
			type: 'select',
			options: [],
		}))

		expect(filtersFromSchema(derivedListShape)).toEqual([])
		expect(filtersFromSchema(null)).toEqual([])
	})

	it('returns an empty properties bag for an empty dimension set', () => {
		expect(buildFacetDimensionSchema({})).toEqual({ properties: {} })
		expect(buildFacetDimensionSchema(null)).toEqual({ properties: {} })
	})
})
