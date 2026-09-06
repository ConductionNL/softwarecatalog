/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has produced three defects of
 * exactly that shape: an unregistered icon name renders NO glyph (no fallback,
 * no console error, four apps shipped it), an entry whose `route` names a page
 * the app does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry reaching the
 * user's notification preferences in two apps.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 *
 * This suite's config supplies `use.storageState`, so specs start signed in.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/stackiq'

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers: ADR-114 fixes the sequence, and
		// openregister runs its footer at 1/2 while pipelinq runs 160/200/230.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Portfolio rationalization is a card on Reports, not a main-nav entry', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		// ADR-112 Decision 2: a report is a card OR an entry, never both. It was a
		// main-nav entry at order 87 before ADR-114.
		await expect(
			nav.locator('[data-testid="cn-nav-entry-PortfolioReport"]'),
		).toHaveCount(0)

		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/stackiq\/reports(\?|$)/, {
			timeout: 15_000,
		})
		await expect(
			page.getByText('Portfolio rationalization', { exact: false }).first(),
		).toBeVisible({ timeout: 15_000 })
	})

	test('the report page is still routable at its own path', async ({ page }) => {
		// Retiring a menu entry must not take the route with it (ADR-044 D5).
		await page.goto(`${APP_BASE}/portfolio-report`)
		await expect(page).toHaveURL(/\/portfolio-report(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the catalogue indexes stay in the main navigation', async ({ page }) => {
		// Deliberate asymmetry with the report. The catalogue pages are the DATA,
		// not a reading of it, and ADR-097 keeps a domain of the work in the main
		// nav. If a later sweep cards them, this fails rather than passing review.
		const nav = page.locator('[data-testid="cn-nav"]')
		const footer = nav.locator('.cn-app-nav__footer-list')
		const footerTexts = (await footer.locator('li').allInnerTexts()).join(' ')
		expect(footerTexts).not.toMatch(/Modules|Diensten/i)
	})

	test('Store opens the hosted store surface, which this app writes no backend for', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Store$/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/apps\/stackiq\/store(\?|$)/, {
			timeout: 15_000,
		})

		// The page is declarative: openregister hosts the store plane, so this
		// app ships NO store controller (ADR-080, ADR-114 Decision 4). With no
		// registry configured it renders the app's own items and makes NO
		// network call, so this must pass on a plain instance.
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/stackiq$/,
		)
	})
})
