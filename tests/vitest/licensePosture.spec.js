/**
 * Unit tests for the software-license-posture aggregation utility.
 *
 * Covers deployment weighting (not catalogue rows), the phased-out exclusion,
 * the Unknown-licentietype bucket, the open-source share, the per-vendor rollup
 * with cost CONSUMED from contract-administration (and the no-contract degrade),
 * and the per-organisation open-source-first report incl. closed contributors.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	deploymentCount,
	LICENSE_TYPE,
	normaliseLicenseType,
	perOrganisationPosture,
	perVendorRollup,
	portfolioPosture,
} from '../../src/utils/licensePosture.js'

// Modules: M1 open (vendor VA), M2 closed (vendor VA), M3 unknown-type (vendor VB),
// M4 closed but NEVER deployed (vendor VB).
const modules = [
	{
		id: 'M1',
		name: 'Open App',
		licentietype: 'Open source',
		licence: 'EUPL-1.2',
		provider: 'VA',
	},
	{
		id: 'M2',
		name: 'Closed App',
		licentietype: 'Closed source',
		licence: 'Proprietary',
		provider: 'VA',
	},
	{ id: 'M3', name: 'Mystery App', licentietype: '', provider: 'VB' },
	{ id: 'M4', name: 'Shelfware', licentietype: 'Closed source', provider: 'VB' },
]

function inProd(extra) {
	return {
		startDateInProduction: '2025-01-01',
		startDateOutPhased: '',
		...extra,
	}
}

// Usages: M1 deployed twice (O1, O2); M2 once (O1); M3 once (O1); M4 phased out (O1).
const usages = [
	inProd({ id: 'G1', module: 'M1', consumer: 'O1' }),
	inProd({ id: 'G2', module: 'M1', consumer: 'O2' }),
	inProd({ id: 'G3', module: 'M2', consumer: 'O1' }),
	inProd({ id: 'G4', module: 'M3', consumer: 'O1' }),
	{
		id: 'G5',
		module: 'M4',
		consumer: 'O1',
		startDateInProduction: '2024-01-01',
		startDateOutPhased: '2025-06-01',
	},
]

describe('licensePosture.normaliseLicenseType', () => {
	it('maps known types and folds empty to Unknown', () => {
		expect(normaliseLicenseType('Open source')).toBe(LICENSE_TYPE.OPEN)
		expect(normaliseLicenseType('Closed source')).toBe(LICENSE_TYPE.CLOSED)
		expect(normaliseLicenseType('')).toBe(LICENSE_TYPE.UNKNOWN)
		expect(normaliseLicenseType(undefined)).toBe(LICENSE_TYPE.UNKNOWN)
	})
})

describe('licensePosture.deploymentCount', () => {
	it('counts only in-production usages', () => {
		expect(deploymentCount('M1', usages)).toBe(2)
		expect(deploymentCount('M2', usages)).toBe(1)
		expect(deploymentCount('M4', usages)).toBe(0) // phased out
	})
})

describe('licensePosture.portfolioPosture', () => {
	it('weights by deployment, excludes phased-out, buckets Unknown', () => {
		const p = portfolioPosture(modules, usages)
		// 4 in-production usages: M1×2 (open), M2×1 (closed), M3×1 (unknown).
		expect(p.total).toBe(4)
		expect(p.open).toBe(2)
		expect(p.closed).toBe(1)
		expect(p.unknown).toBe(1)
		// The never-deployed closed M4 does not contribute.
		// open share = open / (open + closed) = 2 / 3.
		expect(p.openShare).toBeCloseTo(2 / 3, 5)
		expect(p.byLicense['EUPL-1.2']).toBe(2)
		expect(p.byLicense.Proprietary).toBe(1)
		expect(p.byLicense[LICENSE_TYPE.UNKNOWN]).toBe(1)
	})

	it('openShare is null when neither open nor closed is present', () => {
		const p = portfolioPosture(
			[{ id: 'MX', licentietype: '' }],
			[inProd({ id: 'GX', module: 'MX' })],
		)
		expect(p.openShare).toBeNull()
		expect(p.unknown).toBe(1)
	})
})

describe('licensePosture.perVendorRollup', () => {
	const contracts = [
		// A monthly contract on G1 (vendor VA usage) → 100 × 12 = 1200/yr.
		{ usage: 'G1', cost: 100, costPeriod: 'Monthly' },
		// A yearly contract on G3 (vendor VA usage) → 500/yr.
		{ usage: 'G3', cost: 500, costPeriod: 'Annually' },
	]

	it('groups by vendor with deployments, mix and consumed annual cost', () => {
		const rows = perVendorRollup(modules, usages, contracts)
		const va = rows.find((r) => r.vendorId === 'VA')
		const vb = rows.find((r) => r.vendorId === 'VB')

		// VA: M1×2 (open) + M2×1 (closed) = 3 deployments.
		expect(va.deployments).toBe(3)
		expect(va.mix[LICENSE_TYPE.OPEN]).toBe(2)
		expect(va.mix[LICENSE_TYPE.CLOSED]).toBe(1)
		// Cost consumed from contract-administration: 1200 + 500 = 1700 (never re-derived here).
		expect(va.annualCost).toBe(1700)

		// VB: M3×1 (unknown); M4 phased out contributes nothing; no contracts → null cost.
		expect(vb.deployments).toBe(1)
		expect(vb.mix[LICENSE_TYPE.UNKNOWN]).toBe(1)
		expect(vb.annualCost).toBeNull()
	})

	it('degrades cost to null when no contracts are supplied', () => {
		const rows = perVendorRollup(modules, usages, [])
		expect(rows.every((r) => r.annualCost === null)).toBe(true)
		// Licence mix + deployments still present.
		expect(rows.find((r) => r.vendorId === 'VA').deployments).toBe(3)
	})
})

describe('licensePosture.perOrganisationPosture', () => {
	it('reports an org open/closed share + closed-source contributors', () => {
		// O1 in-use: M1 (open), M2 (closed), M3 (unknown); M4 phased out excluded.
		const p = perOrganisationPosture('O1', modules, usages)
		expect(p.total).toBe(3)
		expect(p.open).toBe(1)
		expect(p.closed).toBe(1)
		expect(p.unknown).toBe(1)
		expect(p.openShare).toBeCloseTo(0.5, 5)
		expect(p.closedContributors).toEqual(['M2'])
	})

	it('O2 runs only the open app', () => {
		const p = perOrganisationPosture('O2', modules, usages)
		expect(p.total).toBe(1)
		expect(p.open).toBe(1)
		expect(p.openShare).toBe(1)
		expect(p.closedContributors).toEqual([])
	})
})
