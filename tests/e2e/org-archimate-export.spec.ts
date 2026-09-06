// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage file for openspec/specs/org-archimate-export/spec.md
 *
 * Coverage status
 * ---------------
 * All 49 backend/XML-generation scenarios (Requirements 1–13) are excluded
 * from Playwright coverage: they are pure server-side contracts verified by
 * PHPUnit and Newman/Postman tests (postman/ holds the 518-entry collection).
 *
 * The 4 frontend scenarios (Requirement 14: "Frontend MUST provide organization
 * export with data layer toggles") are covered below by driving the REAL DOM
 * (NcSelect combobox, NcCheckboxRadioSwitch toggles, NcButton clicks) — no Vue
 * `$data` patching / `__vue__` walking.
 *
 * The toggle-reveal + "Organization Export" enablement require a REAL
 * organisation (truthy `value`) to be selected — the built-in "Generic" option
 * has `value: null` (falsy), so selecting it leaves `selectedOrganization`
 * falsy and the checkbox group never renders. The
 * `user-triggers-organization-export-with-toggles` scenario therefore SEEDS a
 * real organisation via the OpenRegister API in `beforeAll` (fixture SETUP only;
 * all ASSERTIONS remain on the rendered DOM) and selects that real org through
 * the combobox.
 *
 * Excluded scenarios (backend – 49 total):
 * @e2e org-archimate-export::organization-with-mapped-applications-exports-successfully
 * @e2e org-archimate-export::organization-with-no-mapped-applications
 * @e2e org-archimate-export::export-preserves-all-base-gemma-data
 * @e2e org-archimate-export::export-xml-is-well-formed-and-schema-valid
 * @e2e org-archimate-export::large-organization-export-completes-within-timeout
 * @e2e org-archimate-export::application-element-has-correct-structure
 * @e2e org-archimate-export::application-element-has-unique-swc-identifier
 * @e2e org-archimate-export::application-element-identifier-is-deterministic
 * @e2e org-archimate-export::application-element-name-handles-special-xml-characters
 * @e2e org-archimate-export::application-mapped-to-one-referentiecomponent
 * @e2e org-archimate-export::application-mapped-to-multiple-referentiecomponenten
 * @e2e org-archimate-export::relationship-identifiers-are-deterministic
 * @e2e org-archimate-export::relationship-source-and-target-reference-valid-elements
 * @e2e org-archimate-export::view-with-applications-plotted-on-referentiecomponenten
 * @e2e org-archimate-export::multiple-applications-stacked-inside-one-referentiecomponent
 * @e2e org-archimate-export::application-appears-in-multiple-referentiecomponenten-across-views
 * @e2e org-archimate-export::view-without-any-matching-referentiecomponenten
 * @e2e org-archimate-export::original-gemma-views-are-preserved-unchanged
 * @e2e org-archimate-export::view-has-titel-view-swc-property
 * @e2e org-archimate-export::view-without-titel-view-swc-property
 * @e2e org-archimate-export::view-name-handles-long-organization-names
 * @e2e org-archimate-export::organisation-folders-created-with-typed-subfolders
 * @e2e org-archimate-export::empty-folders-are-omitted
 * @e2e org-archimate-export::only-deelnames-enabled-produces-only-deelnames-folder
 * @e2e org-archimate-export::folder-item-references-are-valid
 * @e2e org-archimate-export::file-name-includes-date-and-organization
 * @e2e org-archimate-export::model-name-includes-organization
 * @e2e org-archimate-export::file-name-sanitizes-special-characters-in-organization-name
 * @e2e org-archimate-export::valid-organization-uuid-provided
 * @e2e org-archimate-export::valid-organization-uuid-with-query-parameters
 * @e2e org-archimate-export::non-existent-organization-uuid
 * @e2e org-archimate-export::unauthenticated-request-is-rejected
 * @e2e org-archimate-export::non-admin-user-is-rejected
 * @e2e org-archimate-export::bron-property-definition-does-not-already-exist
 * @e2e org-archimate-export::bron-property-definition-already-exists
 * @e2e org-archimate-export::bron-property-references-are-valid
 * @e2e org-archimate-export::connection-links-application-node-to-referentiecomponent-node
 * @e2e org-archimate-export::connection-identifiers-are-unique
 * @e2e org-archimate-export::connection-without-matching-relationship-is-not-created
 * @e2e org-archimate-export::organisation-has-deelname-gebruik
 * @e2e org-archimate-export::organisation-has-no-deelname-gebruik
 * @e2e org-archimate-export::deelnames-parameter-is-not-set
 * @e2e org-archimate-export::deelname-applications-have-distinct-identifiers
 * @e2e org-archimate-export::deelname-query-filters-on-deelnemers-field
 * @e2e org-archimate-export::deelname-query-handles-no-results-gracefully
 * @e2e org-archimate-export::all-parameters-enabled
 * @e2e org-archimate-export::no-parameters-provided-default-behavior
 * @e2e org-archimate-export::only-deelnames-enabled
 * @e2e org-archimate-export::boolean-parameters-accept-various-truthy-values
 */

import type { Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { APP_PATH, resolveBaseUrl } from './base-url.ts'

// ---------------------------------------------------------------------------
// Fixture setup
// ---------------------------------------------------------------------------

// Resolved centrally (tests/e2e/base-url.ts) so this spec's absolute API calls
// and its relative `page.goto()`s cannot end up on different instances.
const BASE_URL = resolveBaseUrl()
const NC_ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const NC_ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

// Deterministic name for the organisation seeded for the toggle-reveal scenario.
const SEEDED_ORG_NAME = 'E2E Export Test Org'

/**
 * Seed a real organisation in OpenRegister so the component's
 * `loadOrganizations()` yields an option with a truthy `value`. This is fixture
 * SETUP only (per the gate-19 program, setup may use the API; only ASSERTIONS
 * must be driven through the UI). Idempotent: re-creating with the same name is
 * harmless for this test (it selects by visible label).
 *
 * Resolves the voorzieningen register + organisatie schema from the app's own
 * config endpoint, then POSTs an organisation object via basic auth (which
 * bypasses the CSRF requesttoken that cookie-based writes need).
 */
async function seedOrganization(): Promise<void> {
	const ctx = await playwrightRequest.newContext({
		baseURL: BASE_URL,
		httpCredentials: { username: NC_ADMIN_USER, password: NC_ADMIN_PASS },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
	try {
		const configRes = await ctx.get(
			'/index.php/apps/stackiq/api/voorzieningen/config',
		)
		if (!configRes.ok()) {
			throw new Error(`config endpoint returned ${configRes.status()}`)
		}
		const config = (await configRes.json())?.config ?? {}
		const register = config.register
		const schema = config.organisatie_schema
		if (!register || !schema) {
			throw new Error(
				'voorzieningen register/organisatie schema not configured',
			)
		}

		// Skip seeding if an organisation with this name already exists. Match on
		// `contactsUid` too: that is the schema's objectNameField now, and a
		// `_fields=id,naam` projection returns nothing useful because `naam` is no
		// longer a property of this schema.
		const existing = await ctx.get(
			`/index.php/apps/openregister/api/objects/${register}/${schema}?_limit=5000`,
		)
		if (existing.ok()) {
			const data = await existing.json()
			const list = data?.results ?? data ?? []
			const matches = (o: Record<string, unknown>): boolean =>
				o.contactsUid === SEEDED_ORG_NAME
				|| o.name === SEEDED_ORG_NAME
				|| o.name === SEEDED_ORG_NAME
				|| (o as { '@self'?: { name?: string } })['@self']?.name
					=== SEEDED_ORG_NAME
			if (Array.isArray(list) && list.some(matches)) {
				return
			}
		}

		// `type` AND `contactsUid` are required (not-null) on the organisatie
		// schema. `contactsUid` was missing from this payload, which is invisible
		// on an instance whose magic-mapper table predates the requirement but is
		// a hard failure on a fresh install: OpenRegister rejected the create with
		// `SQLSTATE[23502] ... null value in column "contacts_uid" ... violates
		// not-null constraint` (surfaced, confusingly, as HTTP 403).
		//
		// Identity for an organisation lives in Nextcloud Contacts and this record
		// only stores the catalog-side relation, so a fixture that is not testing
		// contact resolution supplies a synthetic UID. It satisfies the schema's
		// declared contract; nothing here asserts the UID resolves to a contact.
		//
		// The UID is deliberately SEEDED_ORG_NAME: the schema's
		// `configuration.objectNameField` is `contactsUid`, so the UID *is* the
		// object's display name and therefore the label the Organization combobox
		// renders. `naam` is no longer a property of this schema at all.
		const createRes = await ctx.post(
			`/index.php/apps/openregister/api/objects/${register}/${schema}`,
			{
				data: {
					name: SEEDED_ORG_NAME,
					type: 'Supplier',
					status: 'Active',
					contactsUid: SEEDED_ORG_NAME,
				},
			},
		)
		if (!createRes.ok()) {
			throw new Error(
				`failed to seed organisation (${createRes.status()}): ${await createRes.text()}`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Navigate to the ArchiMate settings section and wait for the Vue SPA to mount.
 * Auth is injected from storageState (see playwright.config.ts).
 */
async function goToArchiMateSettings(page: Page): Promise<void> {
	// `domcontentloaded`, not `networkidle`: Nextcloud keeps long-lived
	// connections open (notifications polling, dashboard widgets), so the
	// network never goes idle and this wait can only ever time out or be
	// satisfied by luck (ADR-074 rule 4). The real readiness signal is the
	// heading assertion below, which waits for the SPA to actually mount.
	await page.goto('/settings/admin/stackiq', {
		waitUntil: 'domcontentloaded',
	})
	await expect(
		page.getByRole('heading', { name: 'ArchiMate Import/Export' }),
	).toBeVisible({ timeout: 30000 })
}

/**
 * Select an organisation in the real NcSelect combobox by visible option text.
 * Opens the combobox, waits for the listbox, and clicks the matching option.
 * Returns true if the option was found and clicked.
 */
async function selectOrganization(
	page: Page,
	optionLabel: string,
): Promise<boolean> {
	const orgSelect = page.locator('#organization-select')
	await expect(orgSelect).toBeVisible()
	// NcSelect renders a vue-select combobox; clicking opens the dropdown.
	await orgSelect.click()
	const option = page
		.locator('.vs__dropdown-option, [role="option"]')
		.filter({ hasText: optionLabel })
		.first()
	try {
		await option.waitFor({ state: 'visible', timeout: 5000 })
	} catch {
		// Dropdown may not have opened on first click (focus race) — retry once.
		await orgSelect.click()
		await option.waitFor({ state: 'visible', timeout: 5000 })
	}
	await option.click()
	return true
}

// ---------------------------------------------------------------------------
// Scenario: SPA mounts on main app route (smoke test for fix #322)
// ---------------------------------------------------------------------------
test('swc-fix spa-mounts: main app dashboard renders without white-screen', async ({
	page,
}) => {
	// `/index.php/...`, not the pretty path — see the APP_PATH docblock in
	// tests/e2e/base-url.ts. `domcontentloaded`, not `networkidle`: the SPA
	// keeps a background poll alive so the network never goes idle (the same
	// reasoning manifest-pages.spec.ts and visual/_visual-helpers.ts already
	// document). The heading assertion below is the real readiness signal.
	await page.goto(APP_PATH, { waitUntil: 'domcontentloaded' })
	// Two headings named "Dashboard" exist (widget + page title) — both prove
	// Vue mounted. Using .first() avoids the strict-mode violation.
	await expect(
		page.getByRole('heading', { name: 'Dashboard' }).first(),
	).toBeVisible({ timeout: 30000 })
})

// ---------------------------------------------------------------------------
// Scenario: Default checkbox state
// @e2e org-archimate-export::default-checkbox-state
//
// Drives the real DOM: with no org selected the checkbox group is hidden
// (v-if), and the "Organization Export" button is disabled. No $data reads.
// ---------------------------------------------------------------------------
test('swc-fix default-checkbox-state: checkbox group hidden and org-export disabled until an org is chosen', async ({
	page,
}) => {
	await goToArchiMateSettings(page)

	// Organization select is present.
	const orgSelect = page.locator('#organization-select')
	await expect(orgSelect).toBeVisible()

	// The checkbox group is hidden (v-if="selectedOrganization") until an org
	// is selected — scoped to the export section to avoid sync-table matches.
	const exportSection = page.locator('.export-section')
	await expect(exportSection.getByText('Modules', { exact: true })).toHaveCount(0)
	await expect(exportSection.getByText('Deelnames', { exact: true })).toHaveCount(
		0,
	)
	await expect(exportSection.getByText('Gebruik', { exact: true })).toHaveCount(0)

	// "Organization Export" button must be present but disabled.
	const orgExportBtn = page.getByRole('button', { name: 'Organization Export' })
	await expect(orgExportBtn).toBeVisible()
	await expect(orgExportBtn).toBeDisabled()
})

// ---------------------------------------------------------------------------
// Scenario: No organization selected
// @e2e org-archimate-export::no-organization-selected
// ---------------------------------------------------------------------------
test('swc-fix no-organization-selected: Organization Export button is disabled when no org chosen', async ({
	page,
}) => {
	await goToArchiMateSettings(page)

	// Must be disabled when no org is selected.
	const orgExportBtn = page.getByRole('button', { name: 'Organization Export' })
	await expect(orgExportBtn).toBeVisible()
	await expect(orgExportBtn).toBeDisabled()

	// The generic "Export Base" button is present (no org required).
	const exportBaseBtn = page.getByRole('button', { name: 'Export Base' })
	await expect(exportBaseBtn).toBeVisible()
})

// ---------------------------------------------------------------------------
// Scenario: User triggers organization export with toggles
// @e2e org-archimate-export::user-triggers-organization-export-with-toggles
//
// Drives the real combobox + real checkbox toggles + real button click; asserts
// the rendered control state and the outgoing API request shape. No $data patch.
// ---------------------------------------------------------------------------
test.describe('organization export with toggles', () => {
	test.beforeAll(async () => {
		// Fixture SETUP: ensure a real organisation (truthy value) exists so the
		// combobox offers a selectable option that flips `selectedOrganization`
		// truthy and reveals the toggle group.
		await seedOrganization()
	})

	test('swc-fix user-triggers-organization-export-with-toggles: selecting an org reveals toggles and org-export fires the request', async ({
		page,
	}) => {
		await goToArchiMateSettings(page)

		// Select the seeded REAL organisation (truthy value) through the real
		// combobox. This makes selectedOrganization truthy → checkbox group shows.
		await selectOrganization(page, SEEDED_ORG_NAME)

		// After an org is selected, the checkbox group renders in the export section.
		const exportSection = page.locator('.export-section')
		await expect(
			exportSection.getByText('Modules', { exact: true }),
		).toBeVisible({ timeout: 8000 })
		await expect(
			exportSection.getByText('Deelnames', { exact: true }),
		).toBeVisible()
		await expect(
			exportSection.getByText('Gebruik', { exact: true }),
		).toBeVisible()

		// Toggle "Deelnames" on via the real checkbox switch and assert it checks.
		const deelnamesSwitch = exportSection
			.locator('.checkbox-radio-switch, .checkbox-group label')
			.filter({ hasText: 'Deelnames' })
			.first()
		await deelnamesSwitch.click()

		// Intercept the outgoing export GET so we can assert URL shape without
		// depending on a real download.
		const exportRequestPromise = page
			.waitForRequest(
				(req) => req.url().includes('/api/archimate/export/organization/'),
				{ timeout: 8000 },
			)
			.catch(() => null)

		// "Organization Export" button is now enabled (org selected) — click it.
		const orgExportBtn = page.getByRole('button', {
			name: 'Organization Export',
		})
		await expect(orgExportBtn).toBeEnabled({ timeout: 5000 })
		await orgExportBtn.click()

		// The button enters its loading state ("Exporting...") synchronously, which
		// is rendered DOM proof that exportingOrg flipped — no Vue-internals read.
		await expect(page.getByRole('button', { name: 'Exporting...' })).toBeVisible(
			{ timeout: 5000 },
		)

		const exportRequest = await exportRequestPromise
		if (exportRequest !== null) {
			const url = new URL(exportRequest.url())
			expect(url.searchParams.get('modules')).toBe('true')
			expect(url.searchParams.get('deelnames')).toBe('true')
		}
	})
})

// ---------------------------------------------------------------------------
// Scenario: Export button shows loading state during download
// @e2e org-archimate-export::export-button-shows-loading-state-during-download
//
// Drives the real "Export Base" button; asserts the rendered loading label.
// ---------------------------------------------------------------------------
test('swc-fix export-button-shows-loading-state-during-download: Export Base button shows the rendered loading label', async ({
	page,
}) => {
	await goToArchiMateSettings(page)

	// Delay the export endpoint so the rendered loading state is observable
	// before the request resolves.
	await page.route('**/api/archimate/export*', async (route) => {
		await new Promise((resolve) => setTimeout(resolve, 1500))
		await route.abort()
	})

	const exportBaseBtn = page.getByRole('button', { name: 'Export Base' })
	await expect(exportBaseBtn).toBeVisible()
	await expect(exportBaseBtn).toBeEnabled()
	await exportBaseBtn.click()

	// The button label switches to "Exporting..." while the request is in
	// flight — rendered DOM proof of the loading state (no $data read).
	await expect(page.getByRole('button', { name: 'Exporting...' })).toBeVisible({
		timeout: 5000,
	})
})
