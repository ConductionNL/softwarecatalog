// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression tests for the settings-section INFO SLOT CONTRACT.
 *
 * Why this exists
 * ---------------
 * `AlwaysVisibleSection.vue` declared `<slot name="info" />`, but FOUR of its
 * five callers pass `<template #info-content>` — the name `CollapsibleSection`
 * uses:
 *
 *   src/views/settings/sections/UserGroupsConfiguration.vue      #info-content
 *   src/views/settings/sections/EmailConfiguration.vue           #info-content
 *   src/views/settings/sections/ArchiMateImportExport.vue        #info-content
 *   src/views/settings/sections/OrganizationSynchronization.vue  #info-content
 *   src/views/settings/sections/VersionInformation.vue           #info
 *
 * All five also set `:has-info-content="true"`, so the (i) button rendered and
 * opened a modal that was COMPLETELY EMPTY for four of them.
 *
 * A mis-named slot is invisible: Vue drops unmatched slot content silently, the
 * build succeeds, eslint is happy, and the modal still opens. Nothing short of
 * mounting the component and looking inside the modal can see it — which is
 * exactly what these tests do.
 *
 * Both sections now resolve `info-content` first and fall back to `info`, so
 * either name works and no caller can be silently empty. Each name is asserted
 * SEPARATELY: a fix that only honoured `info-content` would still leave
 * VersionInformation blank, and the previous wiring passed the `info` case
 * while failing every `info-content` one.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AlwaysVisibleSection from '../../src/components/AlwaysVisibleSection.vue'
import CollapsibleSection from '../../src/components/CollapsibleSection.vue'

const INFO_CONTENT_MARKUP =
	'<p class="probe-info-content">Info content slot rendered</p>'
const INFO_MARKUP = '<p class="probe-info">Info slot rendered</p>'

/**
 * Mount a section and press its (i) button.
 *
 * With `showSaveButton` / `showRefreshButton` left at their defaults the info
 * button is the only button in the header, so it can be addressed positionally
 * without depending on an icon implementation detail.
 *
 * @param {object} component - The section component to mount.
 * @param {object} slots - Slot definitions to pass to the section.
 * @return {Promise<object>} The mounted wrapper, with the info modal open.
 */
async function mountAndOpenInfo(component, slots) {
	const wrapper = mount(component, {
		props: {
			name: 'User groups',
			hasInfoContent: true,
		},
		slots,
	})

	const buttons = wrapper.findAll('button')
	expect(buttons.length).toBeGreaterThan(0)
	await buttons[0].trigger('click')

	return wrapper
}

describe('AlwaysVisibleSection info modal', () => {
	it('does not render the info panel before the (i) button is pressed', () => {
		const wrapper = mount(AlwaysVisibleSection, {
			props: { name: 'User groups', hasInfoContent: true },
			slots: { 'info-content': INFO_CONTENT_MARKUP },
		})

		expect(wrapper.find('.modal-stub').exists()).toBe(false)
		expect(wrapper.find('.probe-info-content').exists()).toBe(false)
	})

	it('renders #info-content inside the info modal', async () => {
		const wrapper = await mountAndOpenInfo(AlwaysVisibleSection, {
			'info-content': INFO_CONTENT_MARKUP,
		})

		const modal = wrapper.find('.modal-stub')
		expect(modal.exists()).toBe(true)
		expect(modal.find('.probe-info-content').exists()).toBe(true)
		expect(modal.text()).toContain('Info content slot rendered')
	})

	it('still renders #info inside the info modal', async () => {
		const wrapper = await mountAndOpenInfo(AlwaysVisibleSection, {
			info: INFO_MARKUP,
		})

		const modal = wrapper.find('.modal-stub')
		expect(modal.exists()).toBe(true)
		expect(modal.find('.probe-info').exists()).toBe(true)
		expect(modal.text()).toContain('Info slot rendered')
	})

	it('prefers #info-content over #info when both are supplied', async () => {
		const wrapper = await mountAndOpenInfo(AlwaysVisibleSection, {
			'info-content': INFO_CONTENT_MARKUP,
			info: INFO_MARKUP,
		})

		const modal = wrapper.find('.modal-stub')
		expect(modal.find('.probe-info-content').exists()).toBe(true)
		expect(modal.find('.probe-info').exists()).toBe(false)
	})
})

describe('CollapsibleSection info modal', () => {
	it('renders #info-content inside the info modal', async () => {
		const wrapper = await mountAndOpenInfo(CollapsibleSection, {
			'info-content': INFO_CONTENT_MARKUP,
		})

		const modal = wrapper.find('.modal-stub')
		expect(modal.exists()).toBe(true)
		expect(modal.find('.probe-info-content').exists()).toBe(true)
	})

	it('also accepts #info, so both sections share one slot API', async () => {
		const wrapper = await mountAndOpenInfo(CollapsibleSection, {
			info: INFO_MARKUP,
		})

		const modal = wrapper.find('.modal-stub')
		expect(modal.exists()).toBe(true)
		expect(modal.find('.probe-info').exists()).toBe(true)
	})

	it('falls back to the empty-state text when neither slot is supplied', async () => {
		const wrapper = await mountAndOpenInfo(CollapsibleSection, {})

		const modal = wrapper.find('.modal-stub')
		expect(modal.exists()).toBe(true)
		expect(modal.text()).toContain('No additional information available.')
	})
})
