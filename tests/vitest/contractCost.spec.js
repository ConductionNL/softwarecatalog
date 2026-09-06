/**
 * Unit tests for the annualised-cost contract utility.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	annualisedCost,
	isOneOff,
	parseAmount,
	PERIOD,
	totalAnnualisedCost,
} from '../../src/utils/contractCost.js'

describe('contractCost.parseAmount', () => {
	it('reads numbers and numeric strings', () => {
		expect(parseAmount(1000)).toBe(1000)
		expect(parseAmount('1000')).toBe(1000)
		expect(parseAmount('1000,50')).toBe(1000.5)
	})
	it('returns null for non-numeric', () => {
		expect(parseAmount('')).toBeNull()
		expect(parseAmount(null)).toBeNull()
		expect(parseAmount('abc')).toBeNull()
	})
})

describe('contractCost.annualisedCost', () => {
	it('annualises a monthly contract ×12', () => {
		expect(annualisedCost({ cost: 1000, costPeriod: PERIOD.MONTHLY })).toEqual({
			annual: 12000,
			oneOff: 0,
		})
	})
	it('passes a yearly contract through ×1', () => {
		expect(annualisedCost({ cost: 6000, costPeriod: PERIOD.YEARLY })).toEqual({
			annual: 6000,
			oneOff: 0,
		})
	})
	it('excludes a one-off from the annual figure', () => {
		expect(annualisedCost({ cost: 5000, costPeriod: PERIOD.ONEOFF })).toEqual({
			annual: 0,
			oneOff: 5000,
		})
	})
	it('yields zeros for unknown period or unparseable amount', () => {
		expect(annualisedCost({ cost: 100, costPeriod: 'Wekelijks' })).toEqual({
			annual: 0,
			oneOff: 0,
		})
		expect(annualisedCost({ cost: 'x', costPeriod: PERIOD.MONTHLY })).toEqual({
			annual: 0,
			oneOff: 0,
		})
		expect(annualisedCost({})).toEqual({ annual: 0, oneOff: 0 })
	})
	it('reads OR object envelopes', () => {
		expect(
			annualisedCost({
				object: { cost: 1000, costPeriod: PERIOD.MONTHLY },
			}),
		).toEqual({ annual: 12000, oneOff: 0 })
	})
})

describe('contractCost.totalAnnualisedCost', () => {
	it('sums annual and one-off separately across contracts', () => {
		const contracts = [
			{ cost: 1000, costPeriod: PERIOD.MONTHLY }, // 12000 annual
			{ cost: 6000, costPeriod: PERIOD.YEARLY }, // 6000 annual
			{ cost: 5000, costPeriod: PERIOD.ONEOFF }, // 5000 one-off
		]
		expect(totalAnnualisedCost(contracts)).toEqual({
			annual: 18000,
			oneOff: 5000,
		})
	})
	it('handles an empty list', () => {
		expect(totalAnnualisedCost([])).toEqual({ annual: 0, oneOff: 0 })
		expect(totalAnnualisedCost(undefined)).toEqual({ annual: 0, oneOff: 0 })
	})
})

describe('contractCost.isOneOff', () => {
	it('detects Eenmalig', () => {
		expect(isOneOff({ costPeriod: PERIOD.ONEOFF })).toBe(true)
		expect(isOneOff({ costPeriod: PERIOD.MONTHLY })).toBe(false)
	})
})
