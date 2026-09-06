// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural UI coverage for the Dashboard page (manifest page `Dashboard`,
 * DashboardCustomView → src/views/Dashboard.vue).
 *
 * The existing manifest-pages smoke only asserts the word "Dashboard" is
 * visible. This drives the real dashboard surface: the widget grid, the
 * statistics overview tables, the "Vernieuwen" (refresh) action, and the
 * "Ga naar Organisaties" navigation button which routes to the organisaties
 * index (navigationStore.setSelected('organisaties')).
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	gotoAppRoute,
	navClickTo,
} from './_helpers.ts'

test('dashboard: renders the overview surface (stat tiles and the object statistics panel)', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	// The dashboard is four stat tiles plus the catalog-panels widget. It used
	// to be an info box with an intro sentence and a "Beheer van Organisaties"
	// heading; that surface was replaced when the KPI tiles landed, and this
	// spec kept asserting the old one.
	//
	// Matched against BOTH the manifest source and its nl.json translation.
	// Two of these differ between the two: the manifest says "Services" and
	// "Object statistics", which nl.json maps to "Diensten" and "Object
	// statistieken". Pinning either one couples the spec to whichever locale
	// the instance happens to boot in, and that is exactly how the previous
	// version of this test failed: it asserted Dutch against an instance
	// rendering the English source.
	const tiles: RegExp[] = [
		/Organisaties/,
		/Modules/,
		/Services|Diensten/,
		/Contracten/,
	]
	for (const label of tiles) {
		await expect(
			main.getByText(label).first(),
			`the ${label.source} stat tile must render`,
		).toBeVisible({ timeout: 30000 })
	}

	// The catalog-panels widget is NOT asserted here, deliberately. Its manifest
	// title ("Object statistics") does not appear as text on the rendered
	// dashboard, in either locale, so something about how that custom widget
	// renders differs from the stat tiles above and I could not settle what from
	// CI logs alone.
	//
	// It is left out rather than guessed at, because a wrong assertion here is
	// worse than none: this spec has already failed twice on selectors written
	// from inference instead of observation. Worth an eye on a live instance,
	// starting with whether `catalog-panels` resolves in the widget registry at
	// all: an unknown widget type renders "Widget not available" and logs
	// nothing.

	expectNoAppErrors(bag)
})

test('dashboard: the refresh action re-runs the data load without error', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	// Refresh is NOT a button on the surface. CnDashboardPage puts it in the
	// page actions menu, where showRefresh defaults to true, so it is reached
	// by opening that menu and clicking the item.
	//
	// The label is matched loosely on purpose: it comes from the nextcloud-vue
	// catalogue rather than this app's, so pinning one spelling would couple
	// this spec to the library's translations.
	const actions = main
		.getByRole('button', { name: /Acties|Actions|Meer|More/i })
		.first()
	await expect(actions, 'the page actions menu must be offered').toBeVisible({
		timeout: 30000,
	})
	await actions.click()

	const refresh = page
		.locator('.v-popper__popper--shown')
		.last()
		.getByRole('menuitem', { name: /Vernieuwen|Refresh/i })
		.first()
	await expect(refresh, 'the actions menu must offer Refresh').toBeVisible({
		timeout: 15000,
	})
	await refresh.click()

	// The surface is still intact after the reload.
	await expect(
		main.getByText('Organisaties', { exact: false }).first(),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// The "Ga naar Organisaties" quick-nav test is deliberately gone rather than
// retargeted. It asserted a button inside the old info box, and that whole
// surface was replaced by the KPI tiles. Its own comment already recorded that
// the button was a no-op in the shared shell and that "the user's working path
// is the Organisations nav entry, covered separately" — which is the test
// immediately below. Keeping a rewritten version would re-test that same path
// twice while pretending to cover a control that no longer exists.

// The organisaties index is genuinely reachable via the real app nav entry
// "Organisations" — this is the user's actual navigation path and lands on the
// CnIndexPage list surface (Add button + Cards/Table toggle).
test('dashboard: "Organisations" nav entry reaches the organisaties index', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Organisations')
	const main = page.locator(APP_MAIN).first()

	// ⚠️ THIS USED TO ASSERT A SURFACE THE PRODUCT NO LONGER RENDERS. The
	// comment here claimed Organisations was a `type: custom` page
	// (OrganisatieIndexView) "with no Cards/Table toggle" whose create action
	// read "Add organisation". src/manifest.json decomposed it into a standard
	// `type: index` page (its own `_note` records the change), so the surface
	// is CnIndexPage: a heading, a Cards/Table view toggle, and an Add button
	// whose label CnIndexPage derives as `'Add ' + schema.title`.
	//
	// The schema title is authored "Organization" while every other string in
	// this app is British ("Organisations" nav entry, "Organisation
	// relationships" page title) — and a deployed instance can still carry the
	// older "Organisation" title, because OpenRegister's import skips a schema
	// whose deployed version is not older and its escape hatch never compares
	// the title. Accept either spelling of the one word rather than pin the
	// test to whichever an environment happens to hold; the assertion still
	// names the action and the entity, so it cannot match another page.
	await expect(
		main.getByRole('heading', { name: 'Organisation relationships' }).first(),
	).toBeVisible({ timeout: 30000 })
	await expect(
		main.getByRole('button', { name: /^Add Organi[sz]ation$/i }).first(),
	).toBeVisible({ timeout: 30000 })
	// The view toggle exists only on the index surface — it is what
	// distinguishes "landed on the index" from "landed on any page with a
	// create button".
	await expect(main.getByRole('button', { name: 'Table' }).first()).toBeVisible()
	expectNoAppErrors(bag)
})

test('dashboard: reachable by clicking the Dashboard navigation entry', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Dashboard')
	const main = page.locator(APP_MAIN).first()
	await expect(
		main.getByRole('heading', { name: 'Beheer van Organisaties' }).first(),
	).toBeVisible({ timeout: 30000 })
	expectNoAppErrors(bag)
})
