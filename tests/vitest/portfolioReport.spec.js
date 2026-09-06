/**
 * Unit tests for the portfolio-rationalization-time report's pure
 * display-formatting utilities.
 *
 * Covers the quadrant colour map (NL Design System tokens, never a hardcoded
 * hex for an unknown key), the cloud-transition label formatter, the
 * currency formatter's zero/falsy degrade, the quadrant grouping (every
 * QUADRANT_ORDER key present even with zero rows — Unclassified never
 * silently dropped), and the CSV export URL builder.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildCsvExportUrl,
	cloudTransitionLabel,
	formatCurrency,
	groupRowsByQuadrant,
	QUADRANT_ORDER,
	quadrantColor,
} from '../../src/utils/portfolioReport.js'

describe('QUADRANT_ORDER', () => {
	it('lists the four TIME quadrants with Unclassified last', () => {
		expect(QUADRANT_ORDER).toEqual([
			'Tolerate',
			'Invest',
			'Migrate',
			'Eliminate',
			'Unclassified',
		])
	})
})

describe('quadrantColor', () => {
	it('returns a distinct NL Design System token per quadrant', () => {
		const colors = QUADRANT_ORDER.map((key) => quadrantColor(key))
		expect(new Set(colors).size).toBe(5)
		colors.forEach((c) => expect(c).toMatch(/^var\(--color-/))
	})

	it('falls back to the primary element colour for an unknown key', () => {
		expect(quadrantColor('NotARealQuadrant')).toBe(
			'var(--color-primary-element)',
		)
	})
})

describe('cloudTransitionLabel', () => {
	it('renders an em dash when there is no hosting-model data', () => {
		expect(cloudTransitionLabel({})).toBe('—')
		expect(cloudTransitionLabel(null)).toBe('—')
		expect(cloudTransitionLabel(undefined)).toBe('—')
	})

	it('renders a comma-joined "model: count" list', () => {
		expect(cloudTransitionLabel({ SaaS: 3, IaaS: 1 })).toBe('SaaS: 3, IaaS: 1')
	})
})

describe('formatCurrency', () => {
	it('renders an em dash for zero/falsy amounts', () => {
		expect(formatCurrency(0)).toBe('—')
		expect(formatCurrency(null)).toBe('—')
		expect(formatCurrency(undefined)).toBe('—')
	})

	it('formats a positive amount as a euro figure', () => {
		const result = formatCurrency(12000)
		expect(result).toContain('12')
		expect(result).not.toBe('—')
	})
})

describe('groupRowsByQuadrant', () => {
	const rows = [
		{ uuid: 'g1', quadrant: 'Migrate' },
		{ uuid: 'g2', quadrant: 'Migrate' },
		{ uuid: 'g3', quadrant: 'Unclassified' },
	]

	it('returns one group per QUADRANT_ORDER key, in order', () => {
		const grouped = groupRowsByQuadrant(rows)
		expect(grouped.map((g) => g.key)).toEqual(QUADRANT_ORDER)
	})

	it('places rows into their matching quadrant group', () => {
		const grouped = groupRowsByQuadrant(rows)
		const migrate = grouped.find((g) => g.key === 'Migrate')
		expect(migrate.rows).toHaveLength(2)
	})

	it('keeps an EMPTY quadrant group present (Tolerate has zero rows here) rather than omitting it', () => {
		const grouped = groupRowsByQuadrant(rows)
		const tolerate = grouped.find((g) => g.key === 'Tolerate')
		expect(tolerate).toBeDefined()
		expect(tolerate.rows).toEqual([])
	})

	it('keeps the Unclassified group present and populated — never hidden', () => {
		const grouped = groupRowsByQuadrant(rows)
		const unclassified = grouped.find((g) => g.key === 'Unclassified')
		expect(unclassified.rows).toHaveLength(1)
	})

	it('degrades to empty groups for a non-array input rather than throwing', () => {
		expect(() => groupRowsByQuadrant(null)).not.toThrow()
		expect(
			groupRowsByQuadrant(undefined).every((g) => g.rows.length === 0),
		).toBe(true)
	})
})

describe('buildCsvExportUrl', () => {
	const base = '/index.php/apps/stackiq/api/portfolio-report'

	it('builds a URL with the organisation and format=csv query params', () => {
		const url = buildCsvExportUrl(base, 'org-a')
		expect(url).toBe(`${base}?organisation=org-a&format=csv`)
	})

	it('URL-encodes the organisation uuid', () => {
		const url = buildCsvExportUrl(base, 'org a/b')
		expect(url).toContain(encodeURIComponent('org a/b'))
	})

	it('returns an empty string when no organisation is selected — never a bare/unscoped export URL', () => {
		expect(buildCsvExportUrl(base, '')).toBe('')
		expect(buildCsvExportUrl(base, null)).toBe('')
		expect(buildCsvExportUrl(base, undefined)).toBe('')
	})
})
