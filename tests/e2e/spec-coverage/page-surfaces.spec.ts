import type { Page } from '@playwright/test'

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for four manifest page surfaces that had no
 * browser-level proof at all, and for the EOL-sync settings section.
 *
 * Every test below drives the REAL UI: it clicks the app's own navigation the
 * way a user does (or opens the Nextcloud admin settings section), then asserts
 * the app logged no console error and returned no 5xx.
 *
 * ASSERTION LEVEL — these assert on the ITEM, not the container. Asserting the
 * `<main>` region, the page title, or a word that also appears in the nav would
 * pass on a blank page and on the WRONG page: the shell renders `main` and
 * echoes the nav label for every route. So each test asserts on markup only the
 * page component under test declares — the GEMMA dimension filters that
 * `FacetedCatalogIndexView` builds from `DIMENSION_LABELS`, the "New suite"
 * action `SuitesIndexView` puts in its own action slot, the quadrant table
 * `PortfolioReport` renders from its own `quadrantSummary`, and the "Sync now"
 * control that exists only inside `EolSyncSettings`.
 *
 * Those anchors are also dataset-independent: they are declared by the
 * component rather than derived from rows, so an empty seed makes them absent,
 * not merely empty — which is exactly the property that makes them a real
 * check rather than one that cannot fail.
 *
 * Components proved reachable here:
 *   - src/views/FacetedCatalogIndexView.vue   (Applications and Services)
 *   - src/views/suites/SuitesIndexView.vue    (Suites)
 *   - src/views/organisaties/PortfolioReport.vue (Portfolio rationalization)
 *   - src/views/settings/sections/EolSyncSettings.vue (admin settings section)
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md
 * @spec openspec/specs/suite-wizard/spec.md
 * @spec openspec/specs/portfolio-rationalization-time/spec.md
 * @spec openspec/specs/eol-feed-integration/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	gotoAppRoute,
	navClickTo,
} from './_helpers.ts'

/**
 * The four GEMMA dimensions `FacetedCatalogIndexView` declares in
 * `DIMENSION_LABELS` and passes to `CnFacetSidebar` as its `filters` prop.
 * No other page in the app renders this set.
 */
const GEMMA_DIMENSIONS = [
	'Reference component',
	'Standard',
	'Application service',
	'Domain',
]

/** Assert the faceted index's own sidebar rendered, dimension by dimension. */
async function expectGemmaFacetSidebar(page: Page): Promise<void> {
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The sidebar's own title, which this view supplies.
	await expect(
		main.getByText('GEMMA filters', { exact: false }).first(),
	).toBeVisible({ timeout: 30000 })

	for (const dimension of GEMMA_DIMENSIONS) {
		await expect(
			main.getByText(dimension, { exact: false }).first(),
			`GEMMA dimension "${dimension}" missing — the facet sidebar did not render`,
		).toBeVisible({ timeout: 30000 })
	}
}

test('applications index: FacetedCatalogIndexView renders its GEMMA facet sidebar', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Applications')

	await expectGemmaFacetSidebar(page)

	expectNoAppErrors(bag)
})

test('services index: FacetedCatalogIndexView renders the same sidebar for the dienst subject', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Services')

	// Same component, second schema — this is the assertion that proves the
	// view is reused for `dienst` and not only for `module`.
	await expectGemmaFacetSidebar(page)

	expectNoAppErrors(bag)
})

test('suites index: SuitesIndexView renders its own New suite action', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Suites')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The wizard trigger lives in this view's own CnIndexPage action slot; no
	// other index page declares it.
	await expect(
		main.getByRole('button', { name: 'New suite' }).first(),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('portfolio rationalization: PortfolioReport renders its report chrome', async ({
	page,
}) => {
	const bag = collectAppErrors(page)

	// 🔴 THE CARD IS ITS ONLY ENTRY POINT. This clicked a nav entry called
	// "Portfolio rationalization" until #942 moved the report onto the Reports
	// page as a card (ADR-112: a report is a card OR an entry, never both).
	// There is no such nav link any more, so the click waited 30s for a locator
	// that can never resolve — which reads as a broken page rather than a
	// retired menu entry.
	await gotoAppRoute(page, '/reports')
	const reports = page.locator(APP_MAIN).first()
	await expect(reports).toBeVisible({ timeout: 30000 })

	// 🔴 THE CARD'S ACCESSIBLE NAME IS NOT ITS LABEL. CnReportsPage wraps the
	// whole card in one `<a>` — title, description and category — so its
	// accessible name is all three concatenated and
	// `getByRole('link', {name: 'Portfolio rationalization', exact: true})`
	// matches nothing. It is addressed by its own testid and its title span.
	const cards = reports.locator('[data-testid="cn-report-card"]')
	// LIVENESS CONTROL: the grid rendered at all, so a card that does not match
	// below is a missing card rather than a page that never mounted.
	await expect(cards.first()).toBeVisible({ timeout: 30000 })

	await cards
		.filter({
			has: page.locator(
				'.cn-reports-page__card-title:text-is("Portfolio rationalization")',
			),
		})
		.first()
		.click()

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The refresh control is declared unconditionally by THIS component and by
	// nothing else in the app.
	//
	// ⚠️ NOT `[data-testid="pr-summary"]`: that section sits behind
	// `v-else-if="selectedOrg && report"`. On an instance with no organisation
	// selected the page correctly renders its empty state instead, so asserting
	// the summary table would be asserting on seed data, not on the page.
	await expect(
		main.getByRole('button', { name: 'Refresh report' }).first(),
	).toBeVisible({ timeout: 30000 })

	// …and the page is in one of its two legitimate states: the org-picker
	// empty state, or the rendered quadrant summary. Both are this page's own
	// markup; neither is the shell.
	const emptyState = main
		.getByText('Pick an organisation above', { exact: false })
		.first()
	const summary = page.locator('[data-testid="pr-summary"]')
	await expect(emptyState.or(summary)).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('admin settings: EolSyncSettings renders its section and sync control', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	// `domcontentloaded`, not `networkidle`: Nextcloud keeps long-lived polls
	// open so the network never goes idle (ADR-074 rule 4).
	await page.goto('/settings/admin/stackiq', {
		waitUntil: 'domcontentloaded',
	})

	const host = page.locator('#stackiq-settings')
	await expect(host).toBeVisible({ timeout: 30000 })

	// The section name and the manual-trigger button are both declared by
	// EolSyncSettings.vue and by nothing else in the settings shell.
	await expect(
		host.getByText('End-of-life feed sync', { exact: false }).first(),
	).toBeVisible({ timeout: 30000 })
	await expect(host.getByRole('button', { name: 'Sync now' }).first()).toBeVisible(
		{ timeout: 30000 },
	)

	expectNoAppErrors(bag)
})
