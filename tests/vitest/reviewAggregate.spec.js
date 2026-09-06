/**
 * Unit tests for the ratings & reviews aggregate panel helpers
 * (catalog-ratings, stackiq#375).
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
 */

import { describe, expect, it } from 'vitest'
import {
	aggregatePath,
	normaliseAggregate,
} from '../../src/utils/reviewAggregate.js'

describe('reviewAggregate.aggregatePath', () => {
	it('builds the query path with subjectType and subjectId', () => {
		const path = aggregatePath('module', 'module-uuid-1')
		expect(path).toBe(
			'reviews/aggregate?subjectType=module&subjectId=module-uuid-1',
		)
	})

	it('tolerates missing arguments', () => {
		expect(aggregatePath(undefined, undefined)).toBe(
			'reviews/aggregate?subjectType=&subjectId=',
		)
	})
})

describe('reviewAggregate.normaliseAggregate', () => {
	it('passes through a well-formed response', () => {
		const result = normaliseAggregate({
			average: 8.25,
			count: 4,
			items: [{ id: '1' }],
		})
		expect(result).toEqual({ average: 8.25, count: 4, items: [{ id: '1' }] })
	})

	it('defaults a null average, zero count, and empty items for a malformed/missing response', () => {
		expect(normaliseAggregate(null)).toEqual({
			average: null,
			count: 0,
			items: [],
		})
		expect(normaliseAggregate({})).toEqual({
			average: null,
			count: 0,
			items: [],
		})
		expect(
			normaliseAggregate({
				average: 'not-a-number',
				count: 'x',
				items: 'not-an-array',
			}),
		).toEqual({ average: null, count: 0, items: [] })
	})
})
