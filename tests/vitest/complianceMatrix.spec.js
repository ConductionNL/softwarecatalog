/**
 * Unit tests for the compliance-matrix data mapper.
 *
 * Covers the three cell states (verified / claimed / none), evidence
 * detection across all three evidence carriers, unresolved-relation
 * partitioning, deduplication of multiple records for one pair, the
 * organisation-coverage join, the BIO-measure column source
 * (bio-compliance-assessment), and the both-relations-set conflict flag.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildComplianceMatrix,
	buildOrganisationCoverage,
	CELL,
	COLUMN_SOURCE,
	columnLabel,
	hasEvidence,
	partitionCompliancy,
	resolveUuid,
	standardLabel,
} from '../../src/utils/complianceMatrix.js'

describe('complianceMatrix.resolveUuid', () => {
	it('reads bare string UUIDs', () => {
		expect(resolveUuid('abc')).toBe('abc')
		expect(resolveUuid('  abc  ')).toBe('abc')
	})
	it('reads object uuid/id/@self.id', () => {
		expect(resolveUuid({ uuid: 'u1' })).toBe('u1')
		expect(resolveUuid({ id: 'i1' })).toBe('i1')
		expect(resolveUuid({ '@self': { id: 's1' } })).toBe('s1')
	})
	it('returns empty for null/undefined', () => {
		expect(resolveUuid(null)).toBe('')
		expect(resolveUuid(undefined)).toBe('')
	})
})

describe('complianceMatrix.hasEvidence', () => {
	it('detects a base64 bewijs file', () => {
		expect(hasEvidence({ evidence: 'data:application/pdf;base64,AAA' })).toBe(
			true,
		)
		expect(hasEvidence({ evidence: { id: 1 } })).toBe(true)
	})
	it('detects a bewijsReferentie NC Files link', () => {
		expect(hasEvidence({ evidenceReference: '/Files/proof.pdf' })).toBe(true)
	})
	it('detects a url', () => {
		expect(hasEvidence({ url: 'https://example.org/proof' })).toBe(true)
	})
	it('returns false with no evidence', () => {
		expect(hasEvidence({})).toBe(false)
		expect(hasEvidence({ evidence: '', evidenceReference: '  ', url: '' })).toBe(
			false,
		)
		expect(hasEvidence(null)).toBe(false)
	})
})

describe('complianceMatrix.partitionCompliancy', () => {
	it('separates resolved relations from unresolved standaardGemma-only records', () => {
		const { resolved, unresolved } = partitionCompliancy([
			{ module: 'm1', standard_version: 's1', url: 'https://e' },
			{ module: 'm1', standardGemma: 'GEMMA-ZGW' },
			{ module: 'm2', standard_version: { uuid: 's2' } },
			{ module: 'm3' },
		])
		expect(resolved).toHaveLength(2)
		expect(unresolved).toHaveLength(1)
		expect(unresolved[0].standardGemma).toBe('GEMMA-ZGW')
		expect(resolved[0].evidenced).toBe(true)
		expect(resolved[1].evidenced).toBe(false)
	})
})

describe('complianceMatrix.buildComplianceMatrix', () => {
	const modules = [
		{ uuid: 'mA', name: 'App A' },
		{ uuid: 'mB', name: 'App B' },
	]
	const standardVersions = [
		{ uuid: 's1', name: 'ZGW API' },
		{ uuid: 's2', name: 'Haal Centraal' },
	]

	it('renders the three cell states correctly', () => {
		const compliancy = [
			{ module: 'mA', standard_version: 's1', url: 'https://proof' },
			{ module: 'mA', standard_version: 's2' },
			{ module: 'mB', standard_version: 's1' },
		]
		const { rows, columns } = buildComplianceMatrix({
			modules,
			standardVersions,
			compliancy,
		})
		expect(columns.map((c) => c.label)).toEqual(['ZGW API', 'Haal Centraal'])
		expect(rows[0].cells.s1.state).toBe(CELL.VERIFIED)
		expect(rows[0].cells.s2.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.s1.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.s2.state).toBe(CELL.NONE)
		expect(rows[1].cells.s2.record).toBeNull()
	})

	it('only produces selected standard columns (filter-first, no cartesian wall)', () => {
		const { columns } = buildComplianceMatrix({
			modules,
			standardVersions: [{ uuid: 's1', name: 'ZGW API' }],
			compliancy: [],
		})
		expect(columns).toHaveLength(1)
	})

	it('keeps the strongest state when two records cover one pair', () => {
		const compliancy = [
			{ module: 'mA', standard_version: 's1' },
			{ module: 'mA', standard_version: 's1', url: 'https://proof' },
		]
		const { rows } = buildComplianceMatrix({
			modules,
			standardVersions,
			compliancy,
		})
		expect(rows[0].cells.s1.state).toBe(CELL.VERIFIED)
		expect(rows[0].cells.s1.record).toBeTruthy()
	})

	it('surfaces unresolved standaardGemma-only records separately', () => {
		const { unresolved } = buildComplianceMatrix({
			modules,
			standardVersions,
			compliancy: [{ module: 'mA', standardGemma: 'GEMMA-X' }],
		})
		expect(unresolved).toHaveLength(1)
		expect(unresolved[0].standardGemma).toBe('GEMMA-X')
	})
})

describe('complianceMatrix.standardLabel', () => {
	it('falls back through name fields', () => {
		expect(standardLabel({ name: 'A' })).toBe('A')
		expect(standardLabel({ titel: 'B' })).toBe('B')
		expect(standardLabel({ uuid: 'u' })).toBe('u')
	})
})

describe('complianceMatrix.buildOrganisationCoverage', () => {
	it('lists every gebruik including applications with no compliance data', () => {
		const usages = [{ module: 'mA' }, { module: 'mB' }, { module: 'mC' }]
		const compliancy = [
			{ module: 'mA', standard_version: 's1', url: 'https://e' },
			{ module: 'mB', standard_version: 's1' },
		]
		const coverage = buildOrganisationCoverage({
			usages,
			standaardversieUuid: 's1',
			compliancy,
		})
		expect(coverage).toHaveLength(3)
		expect(coverage[0].state).toBe(CELL.VERIFIED)
		expect(coverage[1].state).toBe(CELL.CLAIMED)
		expect(coverage[2].state).toBe(CELL.NONE)
	})

	it('ignores compliancy for other standards', () => {
		const coverage = buildOrganisationCoverage({
			usages: [{ module: 'mA' }],
			standaardversieUuid: 's1',
			compliancy: [{ module: 'mA', standard_version: 's2', url: 'https://e' }],
		})
		expect(coverage[0].state).toBe(CELL.NONE)
	})
})

describe('complianceMatrix — bioMaatregel column source (bio-compliance-assessment)', () => {
	const modules = [
		{ uuid: 'mA', name: 'App A' },
		{ uuid: 'mB', name: 'App B' },
	]
	const bioMaatregelen = [
		{ uuid: 'b1', name: 'Toegangsbeveiligingsbeleid' },
		{ uuid: 'b2', name: 'Cryptografisch beleid' },
	]

	it('partitions bioMaatregel-linked records as resolved under the bioMaatregel column source', () => {
		const { resolved, unresolved } = partitionCompliancy(
			[
				{ module: 'mA', bioMeasure: 'b1', url: 'https://e' },
				{ module: 'mA', standard_version: 's1' },
			],
			COLUMN_SOURCE.BIO_MAATREGEL,
		)
		expect(resolved).toHaveLength(1)
		expect(resolved[0].columnUuid).toBe('b1')
		expect(resolved[0].evidenced).toBe(true)
		// standaardversie-only records are not applicable to the BIO matrix and
		// have no string fallback, so they are dropped rather than unresolved.
		expect(unresolved).toHaveLength(0)
	})

	it('renders the three cell states for a BIO-measure matrix, same as the standards matrix', () => {
		const compliancy = [
			{ module: 'mA', bioMeasure: 'b1', url: 'https://proof' },
			{ module: 'mA', bioMeasure: 'b2' },
			{ module: 'mB', bioMeasure: 'b1' },
		]
		const { rows, columns } = buildComplianceMatrix({
			modules,
			columns: bioMaatregelen,
			compliancy,
			columnSource: COLUMN_SOURCE.BIO_MAATREGEL,
		})
		expect(columns.map((c) => c.label)).toEqual([
			'Toegangsbeveiligingsbeleid',
			'Cryptografisch beleid',
		])
		expect(rows[0].cells.b1.state).toBe(CELL.VERIFIED)
		expect(rows[0].cells.b2.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.b1.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.b2.state).toBe(CELL.NONE)
	})

	it('flags a record with both standaardversie and bioMaatregel set as conflicted, matched to neither column', () => {
		const compliancy = [
			{
				module: 'mA',
				standard_version: 's1',
				bioMeasure: 'b1',
				url: 'https://e',
			},
		]
		const standardsMatrix = buildComplianceMatrix({
			modules,
			columns: [{ uuid: 's1', name: 'ZGW API' }],
			compliancy,
			columnSource: COLUMN_SOURCE.STANDAARDVERSIE,
		})
		const bioMatrix = buildComplianceMatrix({
			modules,
			columns: bioMaatregelen,
			compliancy,
			columnSource: COLUMN_SOURCE.BIO_MAATREGEL,
		})
		expect(standardsMatrix.rows[0].cells.s1.state).toBe(CELL.NONE)
		expect(standardsMatrix.conflicted).toHaveLength(1)
		expect(bioMatrix.rows[0].cells.b1.state).toBe(CELL.NONE)
		expect(bioMatrix.conflicted).toHaveLength(1)
	})

	it('computes organisation coverage for a bioMaatregel column identically to the standards path', () => {
		const usages = [{ module: 'mA' }, { module: 'mB' }, { module: 'mC' }]
		const compliancy = [
			{ module: 'mA', bioMeasure: 'b1', url: 'https://e' },
			{ module: 'mB', bioMeasure: 'b1' },
		]
		const coverage = buildOrganisationCoverage({
			usages,
			columnUuid: 'b1',
			compliancy,
			columnSource: COLUMN_SOURCE.BIO_MAATREGEL,
		})
		expect(coverage[0].state).toBe(CELL.VERIFIED)
		expect(coverage[1].state).toBe(CELL.CLAIMED)
		expect(coverage[2].state).toBe(CELL.NONE)
	})
})

describe('complianceMatrix.columnLabel', () => {
	it('falls back through name fields (alias of standardLabel)', () => {
		expect(columnLabel({ name: 'A' })).toBe('A')
		expect(standardLabel({ name: 'A' })).toBe(columnLabel({ name: 'A' }))
	})
})
