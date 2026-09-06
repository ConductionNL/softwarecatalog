import type { Page } from '@playwright/test'

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Shared helpers for the behavioural spec-coverage suite.
 *
 * These drive the REAL UI of the manifest-shell Stackiq SPA:
 * navigation is performed by clicking the actual app-navigation links (not
 * deep-link `goto`, which the shared shell sometimes resets to Dashboard), and
 * all assertions are made against rendered DOM (CnIndexPage surface, action
 * buttons, view toggles, empty-state, dashboard widgets, settings sections) —
 * no Vue `$data`/`__vue__` patching.
 */
import { expect } from '@playwright/test'
import { APP_PATH } from '../base-url.ts'

// Was the hardcoded pretty path `/apps/stackiq`, which only resolves
// behind a rewrite rule. See the APP_PATH docblock in tests/e2e/base-url.ts —
// on the CI runner's `php -S` that path is served by the built-in server's own
// "Not Found" page, and every spec then failed on a 30s app-root timeout that
// read like a mount failure.
export const APP_BASE = APP_PATH
export const APP_SHELL = '.stackiq-app-root'
export const APP_MAIN = 'main'

/**
 * Console-error / 5xx collector that ignores noise NOT originating from the
 * stackiq frontend bundle:
 *  - the NC `user_status` heartbeat 500s (a platform quirk in this dev
 *    container, fired by core not by the app);
 *
 * The previously-suppressed `@resolve:voorzieningen_register` 404s and the
 * deprecated `cronjobs/users` 410 console error have been FIXED at source
 * (the register sentinel now resolves to the numeric id via initial-state, and
 * the Settings section no longer calls the removed users endpoint), so those
 * filters were removed — the suites now assert those errors are absent.
 */
export function collectAppErrors(page: Page): {
	errors: string[]
	serverErrors: string[]
} {
	const errors: string[] = []
	const serverErrors: string[] = []
	const isNoise = (s: string): boolean =>
		s.includes('user_status')
		|| s.includes('heartbeat')
		|| s.includes('Failed to load user status')
		// generic "Failed to load resource" lines carry no URL/app attribution;
		// the real 5xx URLs are captured separately via the response listener.
		|| /^Failed to load resource:/.test(s)
		// The optional ArchiMate `element` register (amefConfig) is not
		// provisioned on every instance ("Register not found"); the matrix
		// renders its empty state regardless, so a missing-element fetch is not
		// an app fault.
		|| /Error fetching element collection/i.test(s)
		|| /Register not found/i.test(s)
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!isNoise(t)) errors.push(t.slice(0, 300))
	})
	page.on('response', (resp) => {
		if (resp.status() < 500) return
		const u = resp.url()
		if (u.includes('user_status') || u.includes('heartbeat')) return
		// Only flag 5xx that come from the stackiq app surface.
		if (u.includes('/apps/stackiq/')) serverErrors.push(`${resp.status()} ${u}`)
	})
	return { errors, serverErrors }
}

/** Assert the collected app-origin error/5xx lists are empty (with context). */
export function expectNoAppErrors(bag: {
	errors: string[]
	serverErrors: string[]
}): void {
	expect(
		bag.serverErrors,
		`stackiq 5xx responses:\n${bag.serverErrors.join('\n')}`,
	).toEqual([])
	expect(bag.errors, `stackiq console errors:\n${bag.errors.join('\n')}`).toEqual(
		[],
	)
}

/**
 * Dismiss the manifest-shell "Support <App>" dialog if it is open.
 *
 * The @conduction/nextcloud-vue CnAppRoot shell auto-opens a support /
 * donate dialog (`data-testid-modal="cn-support-dialog"`) over the app on
 * load. Its modal mask intercepts pointer events, so every nav click /
 * button click fails with "subtree intercepts pointer events" until it is
 * closed. We close it (× button, else Escape) and wait for the mask to
 * detach before any interaction. No-op when the dialog is absent.
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('[data-testid-modal="cn-support-dialog"]').first()
	if ((await dialog.count()) === 0) return
	try {
		await dialog.waitFor({ state: 'visible', timeout: 2000 })
	} catch {
		return
	}
	const closeBtn = dialog.getByRole('button', { name: /close/i }).first()
	if (await closeBtn.count()) {
		await closeBtn.click({ timeout: 5000 }).catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await dialog.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {})
}

/**
 * Dismiss the first-run onboarding walkthrough ("Welcome to Software Catalog").
 * It renders a full-screen dim overlay (`.cn-walkthrough__dim--full`) that sits
 * ABOVE the app chrome and intercepts every pointer event, so any nav click made
 * while the tour is open silently misses its target. The user's own state may or
 * may not have the tour marked seen, so we close it defensively on each route
 * load rather than relying on persisted "seen" state.
 */
export async function dismissWalkthrough(page: Page): Promise<void> {
	const tour = page.locator('.cn-walkthrough').first()

	// ⚠️ Do NOT decide "absent" from a single synchronous `count()`/`isVisible()`
	// probe. The walkthrough mounts asynchronously after the shell, so a probe
	// taken the instant the app root attaches frequently sees nothing, returns
	// early, and the dim then appears over the page a few hundred ms later —
	// after which every click retries for the full test timeout against
	// `.cn-walkthrough__dim--full ... subtree intercepts pointer events`.
	//
	// This is not rare: globalSetup captures storageState BEFORE any app page is
	// opened, so the "tour seen" flag is never in the saved state and EVERY test
	// gets a fresh context in which the tour opens again. It only looked
	// intermittent because the race is usually won.
	//
	// So wait a bounded moment for it to appear; a timeout here means it is
	// genuinely not coming.
	try {
		await tour.waitFor({ state: 'visible', timeout: 3000 })
	} catch {
		return
	}

	const closeBtn = tour.getByRole('button', { name: /close tour/i }).first()
	if (await closeBtn.count()) {
		await closeBtn.click({ timeout: 5000 }).catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await tour.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {})
	// The dim layer is what actually swallows the clicks; make sure it is gone,
	// not merely that the dialog reports itself hidden.
	await page
		.locator('.cn-walkthrough__dim')
		.first()
		.waitFor({ state: 'hidden', timeout: 10000 })
		.catch(() => {})
}

/** Deep-link to a route and wait for the Vue shell + main region to mount. */
export async function gotoAppRoute(page: Page, route: string): Promise<void> {
	// The in-app router runs in HISTORY mode, so a deep link is a plain path.
	// This works only because the AppHost SPA catch-all serves the app shell on
	// any sub-path; if that route ever goes missing these navigations 404 at the
	// server rather than falling back to the dashboard, which is the loud
	// failure we want.
	const base = APP_BASE.endsWith('/') ? APP_BASE.slice(0, -1) : APP_BASE
	const url = route === '/' ? `${base}/` : `${base}${route}`
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await page
		.locator(APP_SHELL)
		.first()
		.waitFor({ state: 'attached', timeout: 30000 })
	await page
		.locator(APP_MAIN)
		.first()
		.waitFor({ state: 'visible', timeout: 30000 })
	await dismissSupportDialog(page)
	await dismissWalkthrough(page)
}

/**
 * The app's OWN navigation. Scoping to it avoids matching Nextcloud's global
 * Applications-menu "Dashboard" entry, which collides with the in-app
 * "Dashboard" nav label — that is what this helper exists for, and the identity
 * check below is unchanged in strength.
 *
 * ⚠️ This used to be `nav:has(a[href*="/apps/stackiq/"])`, which stopped
 * matching ANYTHING under vue-router 4 in HASH mode, because v4's `createHref`
 * strips everything before the `#` and emits hash-relative hrefs
 * (`#/organisaties`) where v3 emitted the base too. The app has since moved to
 * history mode, so full-path hrefs are back — but the id-based selector below
 * is kept deliberately: it identifies the element by a stable handle rather
 * than by an href format the router owns, and so survives the next such change.
 *
 * `nav#app-navigation-vue` is @nextcloud/vue's own NcAppNavigation host and is
 * unique on the page (the other two navs are core's app-menu and user-menu), so
 * it identifies the same element by a stable handle rather than by an href
 * format the router owns.
 */
export function appNav(page: Page) {
	return page.locator('nav#app-navigation-vue').first()
}

/**
 * Navigate by CLICKING the real app-navigation link, the way a user would.
 * Starts from the dashboard so the nav is always present, then clicks the
 * matching app nav entry and waits for the SPA route to settle.
 */
export async function navClickTo(page: Page, navLabel: string): Promise<void> {
	await gotoAppRoute(page, '/')
	const nav = appNav(page)
	const link = nav.getByRole('link', { name: navLabel, exact: true }).first()

	// Some entries live inside a collapsible parent submenu that starts
	// collapsed (e.g. "Reports & Compliance" → "Compliance matrix"), so the
	// nested link is present in the DOM but not yet visible. Expand collapsed
	// parents — exactly what a user does before clicking the child. A collapsed
	// parent's toggle is labelled "Open menu"; opening it flips the label to
	// "Close menu", so re-querying `.first()` each pass walks through the
	// remaining collapsed parents without re-closing the ones just opened.
	if ((await link.isVisible().catch(() => false)) === false) {
		const maxExpansions = await nav
			.locator('button[aria-label="Open menu"]')
			.count()
		for (let i = 0; i < maxExpansions; i++) {
			const toggle = nav.locator('button[aria-label="Open menu"]').first()
			if ((await toggle.isVisible().catch(() => false)) === false) {
				break
			}
			await toggle.click().catch(() => {})
			if (await link.isVisible().catch(() => false)) {
				break
			}
		}
	}

	await link.waitFor({ state: 'visible', timeout: 30000 })
	await link.click()
	await page
		.locator(APP_MAIN)
		.first()
		.waitFor({ state: 'visible', timeout: 30000 })
}

/**
 * Assert a manifest index (CnIndexPage) surface rendered with its real,
 * data-independent controls: the Cards/Table view toggle, the primary
 * "Add ..." button, and a mounted list BODY (either the "No items found"
 * empty-state OR an actual rendered list / table with data).
 *
 * `addLabel` is the exact primary-create button text for the page.
 *
 * Note on the body assertion: now that the `@resolve:voorzieningen_register`
 * sentinel resolves to a real register (it used to 404), some schemas in the
 * dev dataset genuinely return rows (e.g. Module versions shows "Showing 3 of
 * 3"). We therefore assert the list body MOUNTED — empty-state OR a populated
 * list — rather than hard-coding the empty-state, which would be a
 * data-dependent (and now-wrong) assumption.
 */
export async function expectIndexSurface(
	page: Page,
	addLabel: string,
): Promise<void> {
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Cards / Table view toggle is part of every CnIndexPage chrome.
	await expect(main.getByText('Cards', { exact: true }).first()).toBeVisible({
		timeout: 30000,
	})
	await expect(main.getByText('Table', { exact: true }).first()).toBeVisible()

	// Primary create action.
	await expect(
		main.getByRole('button', { name: addLabel, exact: true }).first(),
	).toBeVisible({ timeout: 30000 })

	// List body mounted — empty-state OR a populated list/table. Proves the
	// data layer ran (sentinel resolved), not just the chrome.
	const emptyState = main.getByText('No items found', { exact: false }).first()
	// CnIndexPage renders rows either as a table or as cards; "Showing N of M"
	// is the list header shown once a non-empty collection has loaded.
	const populated = main.getByText(/Showing\s+\d+\s+of\s+\d+/i).first()
	await expect(emptyState.or(populated)).toBeVisible({ timeout: 30000 })
}
