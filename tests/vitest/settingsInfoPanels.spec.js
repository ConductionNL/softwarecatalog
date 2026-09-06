// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every settings-section info panel must actually RENDER.
 *
 * Why this exists
 * ---------------
 * These panels were dead code for a long time. `AlwaysVisibleSection` declared
 * `<slot name="info" />` while four of its five callers passed `#info-content`,
 * so Vue silently dropped their markup and the (i) button opened an empty
 * modal. Nothing rendered it, so nothing could fail on it — and one panel had
 * been quietly broken the whole time:
 *
 *     <li>… Use placeholders like {{ organization.name }} and {{ user.email }}</li>
 *
 * Those braces are LITERAL documentation of the e-mail template placeholders,
 * but Vue compiles them as interpolation against the component, which has no
 * `organization` and no `user`. The moment the slot name was fixed and the
 * panel rendered for the first time, it threw
 * `TypeError: Cannot read properties of undefined (reading 'name')` and broke
 * every Playwright settings test through their shared "no console errors"
 * assertion.
 *
 * What this guards
 * ----------------
 * The sibling spec (`sectionInfoSlot.spec.js`) proves the slot MECHANISM
 * forwards content, but it does so with synthetic probe markup — which is
 * exactly why it could not see this. This spec renders the REAL markup of every
 * `#info` / `#info-content` block under `src/views/settings/sections/` and
 * fails if any of them throws.
 *
 * It works on the template source rather than mounting the whole section
 * component on purpose: the panels are static documentation, and mounting a
 * section drags in the Pinia stores, axios and the Nextcloud runtime that this
 * OFFLINE suite deliberately does without. Compiling and rendering the block
 * reproduces the real failure exactly, with none of that.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import { readdirSync, readFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import * as VueRuntime from 'vue'
import { createApp } from 'vue'

const SECTIONS_DIR = path.resolve(
	import.meta.dirname,
	'../../src/views/settings/sections',
)

/**
 * Pull the inner markup of every `<template #info>` / `<template #info-content>`
 * block out of an SFC source.
 *
 * @param {string} source - Full `.vue` file contents.
 * @return {string[]} The inner markup of each info block found.
 */
function extractInfoBlocks(source) {
	const { descriptor } = parse(source)
	if (!descriptor.template || !descriptor.template.ast) {
		return []
	}

	const blocks = []
	const isInfoSlotTemplate = (node) =>
		node.tag === 'template'
		&& (node.props || []).some(
			(prop) =>
				// 7 === DIRECTIVE
				prop.type === 7
				&& prop.name === 'slot'
				&& prop.arg
				&& ['info', 'info-content'].includes(prop.arg.content),
		)

	const walk = (nodes) => {
		for (const node of nodes || []) {
			if (isInfoSlotTemplate(node) && node.children && node.children.length) {
				const start = node.children[0].loc.start.offset
				const end = node.children[node.children.length - 1].loc.end.offset
				blocks.push(source.slice(start, end))
			}
			walk(node.children)
		}
	}
	walk([descriptor.template.ast])

	return blocks
}

/**
 * Compile a template fragment and render it into a detached element.
 *
 * @param {string} markup - The template fragment to render.
 * @return {{ error: (Error|null), text: string }} Render outcome.
 */
function renderFragment(markup) {
	const { code } = compile(markup, {
		mode: 'function',
		hoistStatic: false,
		prefixIdentifiers: true,
	})

	const render = new Function('Vue', code)(VueRuntime)

	const host = document.createElement('div')
	const app = createApp({ render })
	let error = null
	app.config.errorHandler = (err) => {
		error = err
	}
	app.mount(host)
	const text = host.textContent
	app.unmount()

	return { error, text }
}

const sectionFiles = readdirSync(SECTIONS_DIR)
	.filter((file) => file.endsWith('.vue'))
	.map((file) => ({
		file,
		source: readFileSync(path.join(SECTIONS_DIR, file), 'utf8'),
	}))
	.map((entry) => ({ ...entry, blocks: extractInfoBlocks(entry.source) }))
	.filter((entry) => entry.blocks.length > 0)

describe('settings section info panels', () => {
	it('finds the info panels it is supposed to be guarding', () => {
		// A positive control: if the extraction silently stopped matching, every
		// per-file assertion below would vacuously pass.
		expect(sectionFiles.map((entry) => entry.file).sort()).toEqual([
			'ArchiMateImportExport.vue',
			'EmailConfiguration.vue',
			'OrganizationSynchronization.vue',
			'UserGroupsConfiguration.vue',
			'VersionInformation.vue',
		])
	})

	for (const { file, blocks } of sectionFiles) {
		it(`${file} renders its info panel without throwing`, () => {
			for (const markup of blocks) {
				const { error, text } = renderFragment(markup)
				expect(error).toBeNull()
				expect(text.trim().length).toBeGreaterThan(0)
			}
		})
	}

	it('EmailConfiguration shows its placeholder braces literally', () => {
		const entry = sectionFiles.find((s) => s.file === 'EmailConfiguration.vue')
		const { text } = renderFragment(entry.blocks[0])

		// `v-pre` keeps these as documentation. Interpolating them instead is the
		// exact regression this file exists for, and deleting the line to "fix"
		// the crash would fail here too.
		expect(text).toContain('{{ organization.name }}')
		expect(text).toContain('{{ user.email }}')
	})
})
