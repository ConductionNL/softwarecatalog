// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for the portfolio lifecycle roadmap.
 *
 * Page component under test: src/views/LifecycleRoadmapView.vue.
 *
 * Drives the REAL UI of the LifecycleRoadmap manifest custom page: the nav
 * entry reaches the roadmap surface, which is organisation-first (a selector +
 * guidance until an organisation is picked). The phase-derivation, EOL window
 * and grouping/ordering logic are covered exhaustively by the vitest unit
 * tests on the lifecyclePhase utility.
 *
 * LIVE-RUN NOTE: authored against the built app but NOT deployed to the shared
 * dev instance (served app is the main checkout; deploying the worktree to the
 * shared instance is disallowed by policy). Runs green once deployed; here it
 * carries the @e2e traceability the gate requires.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	navClickTo,
} from './_helpers.ts'
import { LifecycleRoadmapView } from './page-components.ts'

// @e2e application-lifecycle-tracking::roadmap-groups-and-orders-the-portfolio
test('roadmap: nav entry reaches the organisation-first roadmap surface', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, LifecycleRoadmapView)

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// LifecycleRoadmapView's own root, not merely "some page rendered". The
	// previous assertion was an OR over two strings either of which a
	// breadcrumb or the nav entry itself satisfies, so it could pass on a page
	// that is not this component at all.
	const roadmap = main.locator('.roadmapView').first()
	await expect(roadmap).toBeVisible({ timeout: 30000 })

	// Its header: the h2 title and the intro that names what the grouping is.
	await expect(
		roadmap.getByRole('heading', { name: 'Portfolio roadmap', level: 2 }),
	).toBeVisible({ timeout: 30000 })
	await expect(roadmap.locator('.rm-intro')).toContainText(
		/grouped by lifecycle phase/i,
	)

	// The refresh control the view owns (it re-runs loadData()).
	//
	// Queried by its ACCESSIBLE NAME, which is the `aria-label` "Refresh data"
	// — not the visible label "Refresh". When an element carries aria-label,
	// that label wins over its text content, so `{ name: 'Refresh', exact: true }`
	// matches nothing. CI caught this; it is also the assertion worth making,
	// because the accessible name is what a screen-reader user hears.
	await expect(
		roadmap.getByRole('button', { name: 'Refresh data', exact: true }).first(),
	).toBeVisible()

	// Organisation-first: the organisation selector is present and, until an
	// organisation is picked, the roadmap groups are NOT rendered — the
	// "Select an organisation" empty state stands in their place.
	await expect(roadmap.locator('.rm-orgSelect')).toBeVisible({ timeout: 30000 })
	await expect(roadmap.getByText('Select an organisation').first()).toBeVisible({
		timeout: 30000,
	})
	await expect(roadmap.locator('.rm-groups')).toHaveCount(0)

	expectNoAppErrors(bag)
})
