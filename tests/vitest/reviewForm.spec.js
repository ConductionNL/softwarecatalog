/**
 * Unit tests for the submit-a-review form helpers (catalog-ratings, stackiq#375).
 *
 * The mandated security property under test: buildReviewSubmission() never
 * includes an `auteur` (or `status`, `id`, `_owner`, …) key — author
 * identity is bound server-side by ReviewService from the authenticated
 * session, never from client input.
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
 */

import { describe, expect, it } from 'vitest'
import {
	buildReviewPayload,
	buildReviewSubmission,
	isReviewFormValid,
	ratingOptions,
} from '../../src/utils/reviewForm.js'

describe('reviewForm.ratingOptions', () => {
	it('returns exactly 1-10', () => {
		const options = ratingOptions()
		expect(options).toHaveLength(10)
		expect(options[0]).toEqual({ label: '1', value: 1 })
		expect(options[9]).toEqual({ label: '10', value: 10 })
	})
})

describe('reviewForm.isReviewFormValid', () => {
	it('requires a non-empty title and an in-range integer rating', () => {
		expect(isReviewFormValid('Great tool', 8)).toBe(true)
		expect(isReviewFormValid('', 8)).toBe(false)
		expect(isReviewFormValid('   ', 8)).toBe(false)
		expect(isReviewFormValid('Great tool', 0)).toBe(false)
		expect(isReviewFormValid('Great tool', 11)).toBe(false)
		expect(isReviewFormValid('Great tool', 8.5)).toBe(false)
		expect(isReviewFormValid('Great tool', null)).toBe(false)
	})
})

describe('reviewForm.buildReviewPayload', () => {
	it('builds exactly {naam, waardering, beschrijvingLang} — no other keys', () => {
		const payload = buildReviewPayload('Great tool', 9, 'Worked well for us')
		expect(payload).toEqual({
			name: 'Great tool',
			rating: 9,
			longDescription: 'Worked well for us',
		})
		expect(Object.keys(payload).sort()).toEqual([
			'longDescription',
			'name',
			'rating',
		])
	})
})

describe('reviewForm.buildReviewSubmission', () => {
	it('never includes an auteur, status, or any other privileged key', () => {
		const body = buildReviewSubmission(
			'Great tool',
			9,
			'Worked well',
			'module',
			'module-uuid-1',
		)

		expect(body).toEqual({
			review: {
				name: 'Great tool',
				rating: 9,
				longDescription: 'Worked well',
			},
			subjectType: 'module',
			subjectId: 'module-uuid-1',
		})
		expect(body.review).not.toHaveProperty('auteur')
		expect(body.review).not.toHaveProperty('status')
		expect(body.review).not.toHaveProperty('id')
		expect(body.review).not.toHaveProperty('_owner')
	})

	it('threads the subjectType/subjectId through verbatim for either subject', () => {
		const body = buildReviewSubmission(
			'Great service',
			6,
			'',
			'catalogService',
			'dienst-uuid-1',
		)
		expect(body.subjectType).toBe('catalogService')
		expect(body.subjectId).toBe('dienst-uuid-1')
	})
})
