// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural UI coverage for the Features & roadmap page (manifest page
 * `FeaturesRoadmap`, route /features-roadmap).
 *
 * The /features-roadmap route now renders its OWN roadmap surface. Previously
 * the deep-link reset to the Dashboard fallback because the manifest sentinels
 * were never resolved (the shell skipped resolution when the backend manifest
 * endpoint returned HTML), leaving the router mounting an unresolved manifest.
 * With app-side sentinel resolution + the in-memory manifest branch
 * (src/main.js), the resolved manifest is what the router serves, so the
 * roadmap page renders its real content.
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	appNav,
	collectAppErrors,
	expectNoAppErrors,
	gotoAppRoute,
} from './_helpers.ts'

test('features-roadmap: deep-link route mounts the roadmap surface', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/features-roadmap')
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	// The roadmap page renders its own surface: the "Features" heading and the
	// "Suggest feature" primary action.
	await expect(
		main.getByRole('heading', { name: 'Features', exact: true }).first(),
	).toBeVisible({ timeout: 30000 })
	await expect(
		// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
		// suggestion modal (team decision 2026-09-04: the forge is where the
		// conversation happens), and the CTA is an anchor to the forge's
		// feature-request issue form now. An `<a href>` has role `link`.
		main.getByRole('link', { name: /Suggest feature/i }).first(),
	).toBeVisible({ timeout: 30000 })
	expectNoAppErrors(bag)
})

// The manifest declares a `FeaturesRoadmapMenu` entry (order 95), but the
// deployed shell does NOT render a "Features & roadmap" link in the app
// navigation (it exposes Dashboard / Organisations / … / Documentation /
// Settings only). This test pins that observed reality: the roadmap is
// deep-link-only here (see BUG LIST — roadmap nav entry not rendered). Asserting
// it now means a future shell fix that adds the entry will surface as a failure
// to revisit, rather than silently changing behaviour.
test('features-roadmap: roadmap nav entry is rendered by the deployed shell', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')

	const nav = appNav(page)
	// The core list pages DO render nav links — sanity that we resolved the app nav.
	await expect(
		nav.getByRole('link', { name: 'Organisations', exact: true }).first(),
	).toBeVisible({ timeout: 30000 })

	// The manifest FeaturesRoadmap page is navigable (title "Features & roadmap",
	// no hidden/menu flag), so the deployed shell renders its nav entry.
	await expect(
		nav.getByRole('link', { name: 'Features & roadmap', exact: true }).first(),
	).toBeVisible({ timeout: 15000 })

	expectNoAppErrors(bag)
})
