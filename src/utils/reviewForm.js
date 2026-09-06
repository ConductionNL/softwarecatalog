/**
 * Pure helpers for the submit-a-review form (catalog-ratings, stackiq#375).
 *
 * Extracted out of SubmitReviewModal.vue so the review-payload shape — most
 * importantly, that it NEVER includes an `auteur` (or `status`) key — is
 * unit-testable without mounting the NcDialog-based component. Author
 * identity is bound server-side by ReviewService from the authenticated
 * session; the client-side contract this module enforces is simply "never
 * send one".
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The 1-10 rating options for the NcSelect rating input.
 *
 * @return {Array<{label:string, value:number}>} The options.
 * @spec openspec/specs/catalog-ratings/spec.md
 */
export function ratingOptions() {
	const options = []
	for (let value = 1; value <= 10; value++) {
		options.push({ label: String(value), value })
	}
	return options
}

/**
 * Whether the submit-a-review form is ready to submit.
 *
 * @param {string} naam   - The review title.
 * @param {number} rating - The selected rating.
 * @return {boolean} True when the form may be submitted.
 * @spec openspec/specs/catalog-ratings/spec.md
 */
export function isReviewFormValid(naam, rating) {
	return (
		typeof naam === 'string'
		&& naam.trim() !== ''
		&& Number.isInteger(rating)
		&& rating >= 1
		&& rating <= 10
	)
}

/**
 * Build the review payload for `POST /api/reviews`. Deliberately narrow —
 * only naam/waardering/beschrijvingLang are ever included, so an `auteur`
 * (or `status`, `id`, `_owner`, …) can never leak into the request body from
 * this form, regardless of what the caller passes in.
 *
 * @param {string} naam             - The review title.
 * @param {number} rating           - The selected rating (1-10).
 * @param {string} beschrijvingLang - The testimonial text.
 * @return {{name:string, rating:number, longDescription:string}} The payload.
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
 */
export function buildReviewPayload(naam, rating, beschrijvingLang) {
	return {
		name: String(naam || ''),
		rating,
		longDescription: String(beschrijvingLang || ''),
	}
}

/**
 * Build the full `POST /api/reviews` request body (review payload + subject
 * binding). The subject is passed explicitly by the caller (the detail page
 * context), never read from the review payload itself.
 *
 * @param {string} naam             - The review title.
 * @param {number} rating           - The selected rating (1-10).
 * @param {string} beschrijvingLang - The testimonial text.
 * @param {string} subjectType      - 'module' or 'catalogService'.
 * @param {string} subjectId        - The uuid of the module/dienst.
 * @return {{review:object, subjectType:string, subjectId:string}} The request body.
 * @spec openspec/specs/catalog-ratings/spec.md
 */
export function buildReviewSubmission(
	naam,
	rating,
	beschrijvingLang,
	subjectType,
	subjectId,
) {
	return {
		review: buildReviewPayload(naam, rating, beschrijvingLang),
		subjectType,
		subjectId,
	}
}
