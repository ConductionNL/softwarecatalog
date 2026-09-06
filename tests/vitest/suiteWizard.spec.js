/**
 * Unit tests for the suite-wizard pure helpers (vitest mirror of the
 * co-located Jest suite at src/utils/suiteWizard.spec.js — this project runs
 * two test runners; new pure-JS logic gets a vitest spec per PHASE 3 of the
 * suite-wizard implementation plan).
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
 */

import { describe, expect, it } from 'vitest'
import {
	buildSuitePayload,
	isApplicationsStepValid,
	isDetailsStepValid,
	summarizeApplications,
} from '../../src/utils/suiteWizard.js'

describe('suiteWizard.isDetailsStepValid', () => {
	it('requires both naam and beschrijvingKort', () => {
		expect(isDetailsStepValid({ name: '', shortDescription: '' })).toBe(false)
		expect(isDetailsStepValid({ name: 'Suite', shortDescription: '' })).toBe(
			false,
		)
		expect(isDetailsStepValid({ name: '', shortDescription: 'Short' })).toBe(
			false,
		)
		expect(
			isDetailsStepValid({ name: 'Suite', shortDescription: 'Short' }),
		).toBe(true)
	})
})

describe('suiteWizard.isApplicationsStepValid', () => {
	const translate = (app, message) => message

	it('blocks zero applications and allows one or more', () => {
		expect(isApplicationsStepValid([], translate)).not.toBe(true)
		expect(isApplicationsStepValid([{ id: 'mod-1' }], translate)).toBe(true)
	})
})

describe('suiteWizard.buildSuitePayload', () => {
	it('reduces attached module objects to a plain array of ids', () => {
		const payload = buildSuitePayload({
			name: 'Centric Leefomgeving',
			shortDescription: 'Bundled product',
			applications: [{ id: 'mod-1' }, { id: 'mod-2' }],
		})
		expect(payload.applications).toEqual(['mod-1', 'mod-2'])
		expect(payload.name).toBe('Centric Leefomgeving')
	})
})

describe('suiteWizard.summarizeApplications', () => {
	it('lists application names for the confirm step', () => {
		expect(
			summarizeApplications([{ name: 'Module A' }, { id: 'mod-2' }]),
		).toEqual(['Module A', 'mod-2'])
	})
})
