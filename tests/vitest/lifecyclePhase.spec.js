/**
 * Unit tests for the lifecycle-phase derivation utility.
 *
 * Covers every phase boundary (today, future-only, all-unset, out-of-order
 * dates), end-of-support state, the approaching-EOL window, and phase ordering.
 *
 * @spec openspec/changes/application-lifecycle-tracking/specs/application-lifecycle-tracking/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	derivePhase,
	endOfSupportState,
	isEolApproaching,
	parseDate,
	PHASE,
	phaseOrder,
	resolveUuid,
} from '../../src/utils/lifecyclePhase.js'

const NOW = new Date('2026-06-15T12:00:00Z')
const past = (d) => d // helper readability
const future = (d) => d

describe('lifecyclePhase.parseDate', () => {
	it('parses ISO dates and rejects blanks/garbage', () => {
		expect(parseDate('2026-01-01')).toBeInstanceOf(Date)
		expect(parseDate('')).toBeNull()
		expect(parseDate('   ')).toBeNull()
		expect(parseDate('not-a-date')).toBeNull()
		expect(parseDate(null)).toBeNull()
	})
})

describe('lifecyclePhase.derivePhase', () => {
	it('returns the most advanced phase whose date is in the past', () => {
		const gebruik = {
			startDateAcquisition: past('2025-01-01'),
			startDateInProduction: past('2025-06-01'),
			startDateOutPhasing: future('2027-01-01'),
		}
		expect(derivePhase(gebruik, NOW)).toBe(PHASE.PRODUCTION)
	})

	it('advances to Uit te faseren once that date passes (no write)', () => {
		const gebruik = {
			startDateInProduction: '2025-06-01',
			startDateOutPhasing: '2026-01-01',
		}
		expect(derivePhase(gebruik, NOW)).toBe(PHASE.PHASING_OUT)
	})

	it('returns Onbekend when no phase dates are set', () => {
		expect(derivePhase({}, NOW)).toBe(PHASE.UNKNOWN)
		expect(derivePhase({ status: 'whatever' }, NOW)).toBe(PHASE.UNKNOWN)
	})

	it('ignores future-only dates (returns Onbekend)', () => {
		expect(derivePhase({ startDatePlanned: '2027-01-01' }, NOW)).toBe(
			PHASE.UNKNOWN,
		)
	})

	it('tolerates out-of-order dates (most advanced past wins)', () => {
		const gebruik = {
			startDateOutPhased: '2025-01-01', // most advanced + past
			startDateInProduction: '2025-06-01',
		}
		expect(derivePhase(gebruik, NOW)).toBe(PHASE.PHASED_OUT)
	})

	it('treats a date exactly today as past (inclusive boundary)', () => {
		expect(
			derivePhase({ startDateInProduction: '2026-06-15T00:00:00Z' }, NOW),
		).toBe(PHASE.PRODUCTION)
	})

	it('reads an OR object envelope', () => {
		expect(
			derivePhase({ object: { startDateInProduction: '2025-01-01' } }, NOW),
		).toBe(PHASE.PRODUCTION)
	})
})

describe('lifecyclePhase.endOfSupportState', () => {
	it('flags a passed end-of-support date', () => {
		const s = endOfSupportState({ dateEndSupport: '2026-01-01' }, NOW)
		expect(s.passed).toBe(true)
		expect(s.withdrawn).toBe(false)
		expect(s.endDate).toBe('2026-01-01')
	})
	it('flags a withdrawn version', () => {
		const s = endOfSupportState({ dateWithdrawn: '2026-05-01' }, NOW)
		expect(s.withdrawn).toBe(true)
		expect(s.withdrawnDate).toBe('2026-05-01')
	})
	it('does not flag a future end-of-support as passed', () => {
		expect(endOfSupportState({ dateEndSupport: '2027-01-01' }, NOW).passed).toBe(
			false,
		)
	})
})

describe('lifecyclePhase.isEolApproaching', () => {
	it('is true within the window and false outside', () => {
		expect(isEolApproaching({ dateEndSupport: '2026-09-01' }, 180, NOW)).toBe(
			true,
		)
		expect(isEolApproaching({ dateEndSupport: '2027-06-01' }, 180, NOW)).toBe(
			false,
		)
	})
	it('is false for a passed end-of-support (already past, not approaching)', () => {
		expect(isEolApproaching({ dateEndSupport: '2026-01-01' }, 180, NOW)).toBe(
			false,
		)
	})
	it('is false when no end-of-support date', () => {
		expect(isEolApproaching({}, 180, NOW)).toBe(false)
	})
})

describe('lifecyclePhase.phaseOrder', () => {
	it('orders unknown first, then phase progression', () => {
		expect(phaseOrder(PHASE.UNKNOWN)).toBe(0)
		expect(phaseOrder(PHASE.ACQUISITION)).toBe(1)
		expect(phaseOrder(PHASE.PHASED_OUT)).toBe(5)
	})
})

describe('lifecyclePhase.resolveUuid', () => {
	it('resolves string and object relations', () => {
		expect(resolveUuid('u')).toBe('u')
		expect(resolveUuid({ uuid: 'u2' })).toBe('u2')
		expect(resolveUuid(null)).toBe('')
	})
})
