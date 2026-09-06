/**
 * Pure helpers for the ratings & reviews aggregate panel (catalog-ratings,
 * stackiq#375).
 *
 * Extracted out of ReviewsPanel.vue so the query-building and response
 * normalisation are unit-testable without mounting the component.
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Build the `reviews/aggregate` query path for a subject.
 *
 * @param {string} subjectType - 'module' or 'catalogService'.
 * @param {string} subjectId   - The uuid of the module/dienst.
 * @return {string} The path (e.g. `reviews/aggregate?subjectType=module&subjectId=…`).
 * @spec openspec/specs/catalog-ratings/spec.md
 */
export function aggregatePath(subjectType, subjectId) {
	const params = new URLSearchParams({
		subjectType: String(subjectType || ''),
		subjectId: String(subjectId || ''),
	})
	return `reviews/aggregate?${params.toString()}`
}

/**
 * Normalise a `GET /api/reviews/aggregate` response to a stable shape,
 * tolerating a missing/malformed payload.
 *
 * @param {object} data - The raw response body.
 * @return {{average:?number, count:number, items:Array<object>}} The normalised aggregate.
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
 */
export function normaliseAggregate(data) {
	const raw = data || {}
	return {
		average: typeof raw.average === 'number' ? raw.average : null,
		count: typeof raw.count === 'number' ? raw.count : 0,
		items: Array.isArray(raw.items) ? raw.items : [],
	}
}
