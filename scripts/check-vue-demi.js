#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Abort the build unless `vue-demi` is actually on its **Vue 3** shim.
 *
 * `vue-demi` arrives transitively (here: via `vue-codemirror6`) and picks its
 * Vue 2 or Vue 3 shim in a `postinstall` hook. Two ways it silently stays on
 * the Vue 2 shim:
 *
 *   1. `npm install` does NOT re-run a postinstall for an already-present
 *      version, so an incremental install after the Vue 3 dependency bump
 *      leaves the Vue 2 shim in place.
 *   2. This repo's `prebuild`/`predev`/`prewatch` used to run
 *      `vue-demi-switch 2.7`, which forced it back to Vue 2 on **every single
 *      build**. That is what this script replaces.
 *
 * When the shim is wrong, `pinia`/`vue-codemirror6` import a `default` export
 * that `vue@3` does not have — build errors and dead Jest suites with nothing
 * in the output naming `vue-demi`.
 *
 * Behaviour: verify, self-heal once via `vue-demi-switch 3`, verify again,
 * then hard-fail. Never silently continue.
 */

const { spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const SHIM = path.resolve(
	__dirname,
	'..',
	'node_modules',
	'vue-demi',
	'lib',
	'index.mjs',
)

/**
 * Read the resolved vue-demi shim and report whether it is the Vue 3 variant.
 *
 * @return {{ present: boolean, isVue3: boolean }} Shim status.
 */
function inspectShim() {
	if (!fs.existsSync(SHIM)) {
		return { present: false, isVue3: false }
	}
	const src = fs.readFileSync(SHIM, 'utf8')
	// The Vue 3 shim re-exports vue directly and hardcodes `isVue2 = false`.
	// Both markers are checked: the namespace import alone also appears in the
	// Vue 2.7 shim, so it is not sufficient on its own.
	const isVue3 = src.includes('import * as Vue') && src.includes('isVue2 = false')
	return { present: true, isVue3 }
}

const before = inspectShim()

if (!before.present) {
	// vue-demi is transitive; if nothing pulls it in there is nothing to guard.
	process.exit(0)
}

if (before.isVue3) {
	process.exit(0)
}

process.stderr.write(
	'[stackiq] vue-demi is on the Vue 2 shim — switching to Vue 3\n',
)

const bin = path.resolve(
	__dirname,
	'..',
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'vue-demi-switch.cmd' : 'vue-demi-switch',
)

if (fs.existsSync(bin)) {
	spawnSync(bin, ['3'], { stdio: 'inherit' })
}

const after = inspectShim()

if (!after.isVue3) {
	process.stderr.write(
		'[stackiq] FATAL: vue-demi is still on the Vue 2 shim.\n'
			+ '  This app is Vue 3. Building now would produce a bundle whose pinia /\n'
			+ '  vue-codemirror6 imports resolve against a Vue 2 compatibility layer.\n'
			+ '  Fix with a clean install (postinstall hooks always re-run under `npm ci`):\n'
			+ '    rm -rf node_modules && npm ci\n',
	)
	process.exit(1)
}
