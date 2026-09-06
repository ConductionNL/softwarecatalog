/**
 * Every widget icon named in src/manifest.json must exist in the shared
 * CnWidgetGrid icon registry.
 *
 * THE DEFECT UNDER TEST. Four widget icons named MDI glyphs that the registry
 * does not contain: BookOpenVariantOutline, ViewModule, PackageVariant and
 * ShieldLockOutline. `resolveWidgetIcon()` falls back to DEFAULT_ICON for any
 * unknown name, so those four widgets rendered a generic dashboard glyph —
 * silently, with no console warning and no build error. The manifest schema
 * validates the icon field as a string, so `check:manifest` passed too.
 *
 * WHY THIS READS THE INSTALLED PACKAGE RATHER THAN A COPY OF THE LIST.
 * hydra-gates gate-55 carries a hardcoded mirror of the registry and says so
 * in its own comments. A mirror can only ever be as fresh as its last manual
 * edit, and a stale mirror fails in both directions — inventing findings for
 * icons that were added upstream, and passing icons that were removed. This
 * test parses the registry out of the dependency this app actually resolves
 * at build time, so it moves when the dependency moves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { describe, expect, it } from 'vitest'

const here = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(here, '../..')

const REGISTRY_FILE = path.join(
	repoRoot,
	'node_modules/@conduction/nextcloud-vue/src/components/CnWidgetGrid/widgetIcons.js',
)

// THERE ARE TWO REGISTRIES AND AN ICON MUST BE IN BOTH.
//
// CnWidgetGrid resolves widget icons through nc-vue's widgetIcons.js above;
// CnAppNav, CnIcon and the Cn*Page headers resolve through THIS app's own
// src/icons.js (ADR-077). The two lists overlap but are not equal, and the
// failure modes differ: an unknown name in the widget registry renders
// DEFAULT_ICON, while an unknown name in the app registry renders NO ICON AT
// ALL — which src/icons.js says in its own header.
//
// This was learned the hard way. A first pass replaced four unknown widget
// icons with names taken from the widget registry alone; two of them —
// BookOpenVariant and SourceBranch — were absent from src/icons.js, so the
// repair traded a wrong glyph for no glyph. hydra-gates checks the two
// registries in two different gates (55 and 60), so neither gate on its own
// would have said so.
const APP_REGISTRY_FILE = path.join(repoRoot, 'src/icons.js')

/**
 * Extract the registry keys from the installed widgetIcons.js.
 *
 * The registry is an object literal mapping `Name: NameIcon`, so the keys are
 * the lines of the form `\tFoo: FooIcon,`. Anything that does not match that
 * shape is not a registry entry.
 *
 * @return {Set<string>} the icon names the registry recognises.
 */
function readRegistry() {
	const source = fs.readFileSync(REGISTRY_FILE, 'utf8')
	const names = new Set()
	for (const m of source.matchAll(
		/^\s*([A-Z][A-Za-z0-9]*)\s*:\s*[A-Za-z0-9]+Icon\s*,/gm,
	)) {
		names.add(m[1])
	}
	return names
}

/**
 * Extract the icon names this app registers via src/icons.js.
 *
 * The default export is a shorthand object of imported components, so the
 * registered names are the bare identifiers inside it.
 *
 * @return {Set<string>} the icon names the app registry recognises.
 */
function readAppRegistry() {
	const source = fs.readFileSync(APP_REGISTRY_FILE, 'utf8')
	const names = new Set()
	for (const m of source.matchAll(
		/^import\s+([A-Z][A-Za-z0-9]*)\s+from\s+'vue-material-design-icons\//gm,
	)) {
		names.add(m[1])
	}
	return names
}

/**
 * Collect every icon named on a widget, with the page it belongs to.
 *
 * Only widget icons are in scope. Page-level icons are rendered by
 * vue-material-design-icons directly and are not looked up in this registry,
 * and URL-valued icons (`/`, `http`, `data:`) are rendered as <img> — see
 * isCustomIconUrl() in the same module.
 *
 * @param {object} manifest the parsed src/manifest.json.
 * @return {Array<{page: string, widget: string, icon: string}>} the widget icons.
 */
function collectWidgetIcons(manifest) {
	const found = []

	// Widgets live under `config.widgets`, `config.tabs[].widgets` and nested
	// `widgets` arrays, at a depth that varies by page type — so recurse for
	// any `widgets` array rather than hardcoding the path. The first version
	// of this walker assumed `page.widgets` and returned zero, which the
	// "finds widget icons to check" control below caught before the real
	// assertion could pass vacuously.
	const walk = (node, pageId) => {
		if (Array.isArray(node)) {
			for (const item of node) {
				walk(item, pageId)
			}
			return
		}
		if (node === null || typeof node !== 'object') {
			return
		}
		for (const [key, value] of Object.entries(node)) {
			if (key === 'widgets' && Array.isArray(value)) {
				for (const widget of value) {
					const icon = widget?.icon
					if (
						typeof icon === 'string'
						&& icon.length > 0
						&& !icon.startsWith('/')
						&& !icon.startsWith('http')
						&& !icon.startsWith('data:')
					) {
						found.push({ page: pageId, widget: widget.id ?? '?', icon })
					}
				}
			}
			walk(value, pageId)
		}
	}

	for (const page of manifest.pages ?? []) {
		walk(page, page.id ?? page.route ?? '?')
	}
	return found
}

describe('manifest widget icons resolve in the shared registry', () => {
	it('reads a plausible registry from the installed dependency', () => {
		// POSITIVE CONTROL ON THE INPUT. If the registry file moves or its
		// shape changes, readRegistry() returns an empty set and every
		// assertion below passes vacuously — "I found nothing" and "my parser
		// is broken" are the same output otherwise.
		expect(fs.existsSync(REGISTRY_FILE)).toBe(true)
		const registry = readRegistry()
		expect(registry.size).toBeGreaterThan(30)
		// Spot-check both a name that must be there and one that must not.
		expect(registry.has('ViewDashboard')).toBe(true)
		expect(registry.has('ThisIconDoesNotExist')).toBe(false)
	})

	it('finds widget icons to check', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(repoRoot, 'src/manifest.json'), 'utf8'),
		)
		// Same reasoning: a manifest walk that returns nothing would make the
		// real assertion below meaningless.
		expect(collectWidgetIcons(manifest).length).toBeGreaterThan(20)
	})

	it('names no icon the registry does not contain', () => {
		const registry = readRegistry()
		const manifest = JSON.parse(
			fs.readFileSync(path.join(repoRoot, 'src/manifest.json'), 'utf8'),
		)

		const unknown = collectWidgetIcons(manifest)
			.filter(({ icon }) => !registry.has(icon))
			.map(({ page, widget, icon }) => `${page}.${widget}: ${icon}`)

		expect(
			unknown,
			'These widget icons fall back to DEFAULT_ICON and render the wrong glyph',
		).toEqual([])
	})

	it('reads a plausible app registry from src/icons.js', () => {
		// Same positive control, for the second registry.
		expect(fs.existsSync(APP_REGISTRY_FILE)).toBe(true)
		const appRegistry = readAppRegistry()
		expect(appRegistry.size).toBeGreaterThan(30)
		expect(appRegistry.has('AccountGroup')).toBe(true)
		expect(appRegistry.has('ThisIconDoesNotExist')).toBe(false)
	})

	it('names no icon this app has not registered in src/icons.js', () => {
		const appRegistry = readAppRegistry()
		const manifest = JSON.parse(
			fs.readFileSync(path.join(repoRoot, 'src/manifest.json'), 'utf8'),
		)

		const unregistered = collectWidgetIcons(manifest)
			.filter(({ icon }) => !appRegistry.has(icon))
			.map(({ page, widget, icon }) => `${page}.${widget}: ${icon}`)

		expect(
			unregistered,
			'These icons are not in src/icons.js and render NO icon at all, not a fallback',
		).toEqual([])
	})
})
