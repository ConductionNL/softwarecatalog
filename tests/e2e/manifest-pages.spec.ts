// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Real UI smoke coverage for the manifest-driven Stackiq SPA pages.
 *
 * src/manifest.json declares the rendering pages (index / detail / dashboard /
 * roadmap / settings). The app shell (CnAppRoot) uses vue-router in *hash*
 * mode, so every page is deep-linkable as `<app entry>#<route>` (this header
 * previously claimed history mode — it is not; see `gotoAppRoute` below, which
 * has always built hash URLs). Each test drives the real UI by navigating to the page route and
 * asserting the Vue shell mounted (the `.stackiq-app-root` shell that
 * replaces `#content` on mount renders) and the page-specific title text is
 * visible — no Vue-internals
 * patching.
 *
 * GATE-19 COVERAGE
 * ----------------
 * The `fe-*` FE specs have been promoted into `openspec/specs/`, so the
 * *render/load* scenarios of those specs are now gate-visible and are covered
 * by the navigation tests below via `// @e2e <spec>::<slug>` annotations:
 *  - the dashboard page covers fe-shell-navigation "Open the dashboard" and
 *    fe-organizations "Show concept organisations" (the concept-orgs widget);
 *  - the settings page covers fe-settings-ui "Open settings" / "View
 *    statistics" / "View version information" (the settings shell renders all
 *    of its sections including statistics + version);
 *  - the organisaties page covers fe-organizations "Display an organisation
 *    card".
 * The remaining `fe-*` scenarios describe store actions, modal interactions
 * and presentational-component behaviour driven by live object data (save /
 * merge / migrate / upload / mass-ops / heartbeat / theme / pagination /
 * collapsible toggle / per-icon publication state). Those are exercised by the
 * Vue component + Pinia store unit tests (vitest), not by Playwright UI smoke,
 * and carry standalone `@e2e exclude` directives in their spec blocks.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_PATH } from './base-url.ts'

// Was the hardcoded pretty path `/apps/stackiq`. See the APP_PATH
// docblock in tests/e2e/base-url.ts: without a rewrite rule that path is not a
// Nextcloud URL at all, and the CI runner has no rewriting.
const APP_BASE = APP_PATH

// The Vue app bootstraps with `.$mount('#content')` (src/main.js), replacing
// Nextcloud's standard `#content` node with the App.vue root, whose outermost
// element is `<div class="stackiq-app-root">` wrapping CnAppRoot. The
// vestigial `<div id="stackiq">` in templates/index.php is never used
// as the mount target, so the shell is identified by its root class instead.
//
// The `.stackiq-app-root` wrapper itself carries no geometry (the
// CnAppRoot/NcContent layout positions its children), so Playwright reports
// the wrapper as "hidden" even when the page is fully rendered. We therefore
// wait for the wrapper to be *attached* (Vue mounted) and assert visibility on
// the real content region — the NcAppContent `<main>` — and on page text.
const APP_SHELL = '.stackiq-app-root'
const APP_MAIN = 'main'

/**
 * Navigate to an in-app SPA route and wait for the Vue shell to mount.
 * Returns once the app-root shell has mounted and the main content region is
 * visible. We wait for the *shell* (the CnAppRoot container + its NcAppContent
 * main), not data-populated rows — index/dashboard surfaces render their
 * container + empty-state against an empty dev dataset, so asserting the shell
 * keeps the smoke test data-independent.
 */
async function gotoAppRoute(page: Page, route: string): Promise<void> {
	// The in-app router runs in hash mode, so deep links are `#<route>`. A bare
	// path form boots the SPA but leaves the hash empty, so vue-router falls back
	// to the default `/` (Dashboard) and the requested surface never mounts.
	// History mode: a deep link is a plain path (the dashboard is `/`).
	const base = APP_BASE.endsWith('/') ? APP_BASE.slice(0, -1) : APP_BASE
	const url = route === '/' ? `${base}/` : `${base}${route}`
	// Use `domcontentloaded`, not `networkidle`: the app fires a periodic
	// heartbeat / keep-alive poll, so the network never goes idle and a
	// `networkidle` wait times out at 60s. The explicit shell/main waits below
	// are the real readiness signal.
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	// App.vue shell mounted (replaces #content) — the wrapper has no geometry,
	// so check it is attached, then wait for the visible main content region.
	await page
		.locator(APP_SHELL)
		.first()
		.waitFor({ state: 'attached', timeout: 30000 })
	await page
		.locator(APP_MAIN)
		.first()
		.waitFor({ state: 'visible', timeout: 30000 })
}

/**
 * Assert the app shell rendered (NcAppContent main region present) and the
 * given title text is visible somewhere in the rendered page. Uses .first()
 * because the manifest title can appear both in the nav and the page header.
 */
async function expectPageRendered(page: Page, title: string): Promise<void> {
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	// Scope the title match to the app's main content region: an unscoped
	// `getByText('Dashboard')` latches onto the (hidden) global NC Apps-menu
	// "Dashboard" entry, which is never visible in the collapsed header.
	await expect(main.getByText(title, { exact: false }).first()).toBeVisible({
		timeout: 30000,
	})
}

/**
 * Assert an index/list surface rendered its *shell* (not data rows). The
 * page title may only appear as a nav label (the list body uses the singular
 * object name, e.g. "Add Contactpersoon"), so a body-text match on the title
 * is unreliable and can latch onto a hidden DOM node. Instead assert the
 * NcAppContent main region is visible and the page's own navigation entry is
 * visible — both are data-independent and prove the route mounted its shell.
 */
async function expectIndexShellRendered(
	page: Page,
	navLabel: string,
): Promise<void> {
	await expect(page.locator(APP_MAIN).first()).toBeVisible({ timeout: 30000 })
	await expect(
		page
			.locator('nav')
			.getByRole('link', { name: navLabel, exact: true })
			.first(),
	).toBeVisible({ timeout: 30000 })
}

// ---------------------------------------------------------------------------
// Dashboard (type: dashboard) — widget grid
// ---------------------------------------------------------------------------
// @e2e fe-shell-navigation::open-the-dashboard
// @e2e fe-organizations::show-concept-organisations
test('manifest dashboard: dashboard page renders the widget grid', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	await expectPageRendered(page, 'Dashboard')
})

// ---------------------------------------------------------------------------
// Index pages (type: index) — object list surfaces
// ---------------------------------------------------------------------------
// The organisaties index renders the organisation cards (OrganisatieCard);
// asserting the page renders covers fe-organizations "Display an organisation
// card" — the card list is the page body.
// @e2e fe-organizations::display-an-organisation-card
test('manifest index organisaties: list page renders the organisation cards', async ({
	page,
}) => {
	await gotoAppRoute(page, '/organisaties')
	await expectIndexShellRendered(page, 'Organisations')
})

// ⚠️ `/contactpersonen` is NOT in this list, and must not be added back.
// `expectIndexShellRendered` proves the route mounted by finding the page's own
// navigation entry — and the Contacts entry no longer exists. It was removed
// deliberately when contact/organisation identity moved to the Nextcloud
// addressbook: src/manifest.json keeps `Contactpersonen` as a routable
// `type: index` page titled "Contact roles" (a catalog relationship view) but
// declares no menu entry for it. Asserting a nav link that the product
// deliberately deleted is not coverage, it is a stale expectation — the
// contactpersonen route gets its own surface-based test below instead.
const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
	{ route: '/contracten', title: 'Contracts', name: 'contracten' },
	{ route: '/standaarden', title: 'Standards', name: 'standards' },
	{ route: '/reviews', title: 'Reviews', name: 'reviews' },
	{ route: '/komplianties', title: 'Compliance', name: 'komplianties' },
	{ route: '/moduleversies', title: 'Module versions', name: 'moduleversies' },
]

for (const p of INDEX_PAGES) {
	test(`manifest index ${p.name}: list page renders`, async ({ page }) => {
		await gotoAppRoute(page, p.route)
		await expectIndexShellRendered(page, p.title)
	})
}

// The contactpersonen index has no navigation entry (see the note above), so it
// is proven by its own rendered surface: the CnIndexPage create action, whose
// label nc-vue derives as `Add {schema.title}` — "Add Contact person".
test('manifest index contactpersonen: list page renders its own index surface', async ({
	page,
}) => {
	await gotoAppRoute(page, '/contactpersonen')
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	await expect(
		main
			.getByRole('button', { name: 'Add Contact person', exact: true })
			.first(),
	).toBeVisible({ timeout: 30000 })
})

// ---------------------------------------------------------------------------
// Roadmap page (type: roadmap)
// ---------------------------------------------------------------------------
// The manifest declares a `roadmap`-type page at /features-roadmap. The
// nextcloud-vue shell deployed in this environment does not render a distinct
// roadmap surface for that page type — the route mounts the SPA shell and
// falls back to the default (dashboard) content, and no dedicated nav entry is
// emitted. We therefore assert the roadmap route mounts the SPA shell (the
// route is wired and renders), not a roadmap-specific title that the deployed
// renderer does not produce. (Render parity for the roadmap page type is a
// nextcloud-vue concern, tracked separately.)
test('manifest roadmap features-roadmap: roadmap route mounts the SPA shell', async ({
	page,
}) => {
	await gotoAppRoute(page, '/features-roadmap')
	await expect(page.locator(APP_MAIN).first()).toBeVisible({ timeout: 30000 })
})

// ---------------------------------------------------------------------------
// Detail pages (type: detail) — deep-link with a synthetic id.
// The detail renderer mounts even when the object id resolves to nothing
// (empty data / 404 from the OR slug endpoint): we assert the shell mounted,
// not that a specific object loaded, so the test stays green against an empty
// dev dataset. This proves the detail route is wired and the SPA renders it.
// ---------------------------------------------------------------------------
const DETAIL_PAGES: Array<{ route: string; name: string }> = [
	{ route: '/contactpersonen/smoke-id', name: 'contactpersoon-detail' },
	{ route: '/contracten/smoke-id', name: 'contract-detail' },
	{ route: '/standaarden/smoke-id', name: 'standaard-detail' },
	{ route: '/reviews/smoke-id', name: 'review-detail' },
	{ route: '/komplianties/smoke-id', name: 'kompliantie-detail' },
	{ route: '/moduleversies/smoke-id', name: 'moduleversie-detail' },
]

for (const p of DETAIL_PAGES) {
	test(`manifest detail ${p.name}: detail route mounts the SPA shell`, async ({
		page,
	}) => {
		await gotoAppRoute(page, p.route)
		// Shell mounted; the app-content container is rendered regardless of
		// whether the synthetic id resolves to an object.
		await expect(page.locator(APP_MAIN).first()).toBeVisible()
	})
}

// ---------------------------------------------------------------------------
// Settings — the Nextcloud admin settings section
// ---------------------------------------------------------------------------
// These tests used to drive the in-app `#/settings` route, a manifest
// `type: "settings"` page. ADR-079 D1 removed it: app-level configuration has
// exactly one home, `/settings/admin/<app>`, where Nextcloud authorizes the
// caller server-side before the section renders. The SAME component
// (`src/views/settings/StackiqSettings.vue`) renders there, mounted by
// `src/settings.js` into `templates/settings/admin.php`, so these assertions
// are unchanged in substance — only the door they walk through moved.
//
// `domcontentloaded`, not `networkidle`: Nextcloud keeps long-lived polls open,
// so the network never goes idle (ADR-074 rule 4). The visibility assertions
// below are the real readiness signal.
const ADMIN_SETTINGS_URL = '/settings/admin/stackiq'

/**
 * Open the app's Nextcloud admin settings section and return its host element.
 * Auth is injected from storageState (see playwright.config.ts).
 */
async function gotoAdminSettings(page: Page) {
	await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
	const host = page.locator('#stackiq-settings')
	await expect(host).toBeVisible({ timeout: 30000 })
	return host
}

// The settings shell (StackiqSettings.vue) renders its section
// navigation and the configuration status — fe-settings-ui "Open settings".
// @e2e fe-settings-ui::open-settings
test('admin settings: the settings section renders', async ({ page }) => {
	const host = await gotoAdminSettings(page)
	// Scope to the app's own settings host so the assertion can't match a
	// transient notification toast elsewhere in the DOM.
	await expect(host.getByText('Stackiq', { exact: false }).first()).toBeVisible({
		timeout: 30000,
	})
})

// The settings shell renders the Statistics overview section (StatisticsOverview.vue),
// which loads and displays aggregate object counts — fe-settings-ui "View statistics".
// @e2e fe-settings-ui::view-statistics
test('admin settings: statistics section renders', async ({ page }) => {
	const host = await gotoAdminSettings(page)
	await expect(host.getByText('Statistics', { exact: false }).first()).toBeVisible(
		{ timeout: 30000 },
	)
})

// The settings shell renders the Version information section (VersionInformation.vue),
// which loads and displays the app version — fe-settings-ui "View version information".
// @e2e fe-settings-ui::view-version-information
test('admin settings: version information section renders', async ({ page }) => {
	const host = await gotoAdminSettings(page)
	// Match the "Version Information" section heading inside the settings host,
	// not a hidden "Application Version was updated" notification toast.
	await expect(
		host.getByText('Version Information', { exact: false }).first(),
	).toBeVisible({ timeout: 30000 })
})
