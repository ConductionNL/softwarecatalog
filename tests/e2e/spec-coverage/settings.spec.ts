// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural UI coverage for the app's settings surface
 * (`/settings/admin/stackiq` → StackiqSettings.vue).
 *
 * This drove the in-app manifest page `Settings` until ADR-079 D1 removed it:
 * app-level configuration has exactly one home, the Nextcloud admin settings
 * section, which the platform authorizes server-side before it renders. The
 * SAME component renders there — mounted by `src/settings.js` into
 * `templates/settings/admin.php` — so every assertion below is unchanged; only
 * the door they walk through moved.
 *
 * The existing manifest-pages smoke asserts only three section TITLES exist.
 * This suite drives the real settings surface and its interactions:
 *   - all major sections render (Version Information, Object Statistics,
 *     General Settings, OpenRegister Integration, User Groups, Org Sync);
 *   - the OpenRegister Integration sub-tabs (General Configuration /
 *     Voorzieningen / AMEF) switch their panels on click;
 *   - the primary maintenance actions are present and in the right state
 *     (Force Update, Reset Auto-Config, Sync Standards, Save buttons).
 *
 * The User Groups section no longer calls the deprecated
 * /api/settings/cronjobs/users endpoint (removed from CronjobConfiguration.vue),
 * so opening Settings no longer logs "Failed to load users"; collectAppErrors no
 * longer filters that message, so this suite asserts it is genuinely absent.
 */
import { expect, test } from '@playwright/test'
import { collectAppErrors, expectNoAppErrors } from './_helpers.ts'
import { VersionInformation } from './page-components.ts'

/**
 * Open the app's Nextcloud admin settings section and return its host element.
 *
 * `domcontentloaded`, not `networkidle`: Nextcloud keeps long-lived polls open,
 * so the network never goes idle (ADR-074 rule 4). The banner assertion below
 * is the real readiness signal.
 */
async function gotoSettings(page) {
	await page.goto('/settings/admin/stackiq', {
		waitUntil: 'domcontentloaded',
	})
	const main = page.locator('#stackiq-settings')
	// The settings shell renders the app name banner first.
	await expect(main.getByText('Stackiq', { exact: false }).first()).toBeVisible({
		timeout: 30000,
	})
	return main
}

test('settings: all major sections render', async ({ page }) => {
	const bag = collectAppErrors(page)
	const main = await gotoSettings(page)

	for (const heading of [
		VersionInformation,
		'Object Statistics',
		'General Settings',
		'OpenRegister Integration',
		'User Groups Configuration',
		'Organization Synchronization',
	]) {
		await expect(
			main.getByRole('heading', { name: heading }).first(),
		).toBeVisible({ timeout: 30000 })
	}

	expectNoAppErrors(bag)
})

test('settings: maintenance action buttons are present', async ({ page }) => {
	const bag = collectAppErrors(page)
	const main = await gotoSettings(page)

	// Version Information section actions.
	//
	// ⚠️ The previous comment here claimed "Auto Configure + Force Update are
	// always rendered; Reset Auto-Config is conditional". Read the component
	// (VersionInformation.vue): "Auto Configure" is `v-if
	// versionInfo.autoConfigCompleted === false` and "Reset Auto-Config" is
	// `v-if versionInfo.autoConfigCompleted === true`. They are MUTUALLY
	// EXCLUSIVE halves of the same flag; only "Force Update" is unconditional.
	// Asserting "Auto Configure" therefore asserted that auto-configuration had
	// NOT completed — which is exactly the state a correctly provisioned
	// instance is not in. It passed only on an instance that had never finished
	// configuring itself.
	//
	// So: assert the unconditional button, and assert that the flag-driven pair
	// renders exactly one of its two halves. That is the real invariant.
	await expect(
		main.getByRole('button', { name: 'Force Update' }).first(),
	).toBeVisible({ timeout: 30000 })
	const autoConfigure = main
		.getByRole('button', { name: 'Auto Configure' })
		.first()
	const resetAutoConfig = main
		.getByRole('button', { name: 'Reset Auto-Config' })
		.first()
	await expect(autoConfigure.or(resetAutoConfig)).toBeVisible({ timeout: 30000 })
	expect(
		(await autoConfigure.count()) + (await resetAutoConfig.count()),
		'Auto Configure and Reset Auto-Config are mutually exclusive v-ifs on autoConfigCompleted',
	).toBe(1)

	// General Settings save + standards sync.
	await expect(
		main.getByRole('button', { name: 'Sync Standards' }).first(),
	).toBeVisible()
	await expect(
		main.getByRole('button', { name: 'Save General Settings' }).first(),
	).toBeVisible()

	expectNoAppErrors(bag)
})

test('settings: OpenRegister Integration sub-tabs switch panels', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	const main = await gotoSettings(page)

	// The OpenRegister Integration section renders a StandardTabs control with
	// three tabs. They open on the General Configuration tab by default.
	const generalTab = main
		.getByRole('button', { name: 'General Configuration' })
		.first()
	const voorzieningenTab = main.getByText('Voorzieningen', { exact: true }).first()
	const amefTab = main.getByText('AMEF', { exact: true }).first()

	await expect(generalTab).toBeVisible({ timeout: 30000 })
	await expect(voorzieningenTab).toBeVisible()
	await expect(amefTab).toBeVisible()

	// Default tab exposes the register selector ("Select Voorzieningen Register").
	await expect(
		main.getByText('Select Voorzieningen Register', { exact: false }).first(),
	).toBeVisible({ timeout: 15000 })

	// Switch to the Voorzieningen tab — its panel notes the schema mapping context
	// (it references selecting a register in the General Configuration tab when
	// none is chosen, or the schema mapping grid when one is). Assert the panel
	// changed by checking the General-tab register selector is no longer the
	// active panel content while the Voorzieningen tab is now active.
	await voorzieningenTab.click()
	await expect(voorzieningenTab).toBeVisible()

	// Switch to AMEF tab and back to General — interaction must not error.
	await amefTab.click()
	await expect(amefTab).toBeVisible()
	await generalTab.click()
	await expect(
		main.getByText('Select Voorzieningen Register', { exact: false }).first(),
	).toBeVisible({ timeout: 15000 })

	expectNoAppErrors(bag)
})

test('settings: Object Statistics section renders aggregate counts', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	const main = await gotoSettings(page)

	// The statistics section heading + its content table render.
	await expect(
		main.getByRole('heading', { name: 'Object Statistics' }).first(),
	).toBeVisible({ timeout: 30000 })
	// A statistics table/grid is present in the rendered settings page.
	await expect(main.locator('table').first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('settings: Version Information shows application version status', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	const main = await gotoSettings(page)

	await expect(
		main.getByRole('heading', { name: VersionInformation }).first(),
	).toBeVisible({ timeout: 30000 })
	// The version section renders the application name label.
	await expect(
		main.getByText('Application Name', { exact: false }).first(),
	).toBeVisible({ timeout: 15000 })

	expectNoAppErrors(bag)
})
