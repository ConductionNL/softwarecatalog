// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Playwright globalSetup — logs into Nextcloud once and persists the resulting
 * cookie jar to `tests/e2e/.auth/admin.json` so every spec reuses the session.
 *
 * Nextcloud 34 renders the login form via JavaScript so the inputs don't appear
 * in the static HTML. We must wait for the input fields to hydrate before filling.
 */

import type { FullConfig } from '@playwright/test'

import { chromium } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from './base-url.ts'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')

export default async function globalSetup(config: FullConfig): Promise<void> {
	// No `?? 'http://localhost:8080'` — see tests/e2e/base-url.ts. globalSetup
	// performs LOGINS, so an unconfigured run here would fire credentials at
	// whatever instance the fallback named.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseUrl()
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Nextcloud 34 renders the login form entirely via JavaScript.
	// Load the page and wait until the input fields hydrate before filling.
	await page.goto('/index.php/login', { timeout: 60_000 })

	// Try multiple selector strategies covering different NC versions:
	//   - NC 34+ JS-rendered login: inputs get autocomplete attributes
	//   - NC 28-30 server-rendered: inputs have name="user" / name="password"
	const userSelector =
		'input[autocomplete="username"], input[name="user"], input[id="user"]'
	const passSelector =
		'input[autocomplete="current-password"], input[type="password"], input[name="password"]'

	await page.locator(userSelector).waitFor({ state: 'visible', timeout: 45_000 })
	await page.locator(userSelector).fill(username)
	await page.locator(passSelector).fill(password)

	// Click the submit button (may be "Log in" or just type="submit")
	const submitSelector =
		'button[type="submit"], button:has-text("Log in"), input[type="submit"]'
	await page.locator(submitSelector).first().click()

	// Wait for redirect away from /login — the #header only renders when authenticated
	await page.waitForFunction(() => !window.location.pathname.includes('/login'), {
		timeout: 30_000,
	})

	if (page.url().includes('/login')) {
		throw new Error(
			`Nextcloud login failed — still on ${page.url()}. `
				+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS env vars (default: admin/admin).',
		)
	}

	// Persist cookies + localStorage for test reuse
	/*
	 * Suppress the product walkthrough (ADR-043) for automated runs, the way
	 * dossiq's global-setup already does.
	 *
	 * This became load-bearing with @conduction/nextcloud-vue 2.22.x. A
	 * `placement: "center"` welcome step used to be parked in `_pendingAutoTour`
	 * and never opened; the library now correctly starts it on any route, so the
	 * tour actually appears — and its `cn-walkthrough__dim--full` layer is a
	 * `role="dialog" aria-modal="true"` overlay that intercepts every click
	 * behind it. Specs that had never had to account for a tour started timing
	 * out, and `getByRole('dialog').first()` began resolving to the dim layer
	 * instead of the modal under test.
	 *
	 * The marker is per USER, not per test, so without it the suite is also
	 * order-dependent: whichever spec runs first wears the tour and the rest
	 * inherit a dismissed one.
	 *
	 * The sentinel is higher than any real app version, so every step's
	 * `sinceVersion` sorts below it and the tour composes to an empty step set
	 * rather than merely starting dismissed. The page is already on the instance
	 * origin after login, which is the origin storageState persists.
	 */
	try {
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:stackiq', '999.0.0')
				// Same problem, different overlay: the NON-GATING first-time-setup
				// wizard (ADR-042). Dismissing only the walkthrough left this one
				// armed, and its `modal-mask` subtree intercepts every click on
				// the app behind it. The tell is precise: a click reports the
				// target as "visible, enabled and stable" and then times out
				// anyway, with `data-testid-modal="cn-wizard-dialog"` named as
				// the interceptor.
				//
				// It splits a suite rather than failing it — specs that navigate
				// by URL pass, specs that click do not — so it reads as a
				// half-broken app instead of one un-dismissed dialog.
				//
				// The dismissal key is per manifest `setup.version`; seed a
				// generous range so a version bump does not silently re-arm it.
				for (let v = 0; v <= 20; v++) {
					window.localStorage.setItem(
						`cn-setup-wizard-dismissed:stackiq:${v}`,
						'1',
					)
				}
			} catch {
				// localStorage unavailable — specs fall back to dismissing by hand.
			}
		})
	} catch {
		// Never fail setup over an optional convenience.
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
