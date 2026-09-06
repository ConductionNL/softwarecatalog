/**
 * Display helpers for a moderation-queue data bag.
 *
 * The pending-queue items come straight from the OpenRegister object envelope
 * (an arbitrary registration object), so the UI cannot assume a fixed field.
 * These helpers pick a human title + a supporting subtitle from the most likely
 * fields, falling back to the uuid so an item is never rendered blank. Pure +
 * unit-tested so the index view never has to assert this logic itself.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

const TITLE_FIELDS = ['name', 'titel', 'title', 'organization', 'organisation']
const SUBTITLE_FIELDS = [
	'email',
	'contactEmail',
	'url',
	'website',
	'description',
	'description',
]

/**
 * Pick the best human-readable title for a moderation item.
 *
 * @param {object} item - The registration data bag.
 * @return {string} A non-empty display title.
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export function moderationItemTitle(item) {
	const bag = item || {}
	for (const field of TITLE_FIELDS) {
		const value = bag[field]
		if (typeof value === 'string' && value.trim() !== '') {
			return value.trim()
		}
	}
	const id = bag.id || bag.uuid
	return id ? String(id) : 'Untitled registration'
}

/**
 * Pick a supporting subtitle (contact/url/description) for a moderation item,
 * never duplicating the chosen title.
 *
 * @param {object} item - The registration data bag.
 * @return {string} A subtitle, or '' when none is available.
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export function moderationItemSubtitle(item) {
	const bag = item || {}
	const title = moderationItemTitle(bag)
	for (const field of SUBTITLE_FIELDS) {
		const value = bag[field]
		if (
			typeof value === 'string'
			&& value.trim() !== ''
			&& value.trim() !== title
		) {
			return value.trim()
		}
	}
	return ''
}
