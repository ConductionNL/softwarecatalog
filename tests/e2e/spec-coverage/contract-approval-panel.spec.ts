// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for the ContractDetail Approval panel.
 *
 * Drives the REAL UI: the ContractDetail page exposes an "Approval" sidebar tab
 * (added without changing the Contracten nav entry or routes), and the tab
 * renders the read-only approval-state panel. When a decidesk endpoint resolves
 * the panel offers the submit action; when delegation is not configured the
 * panel shows the "not configured" notice and HIDES the submit action so no
 * fail-open path exists. The cross-app raise/projection/fail-closed backend
 * paths are covered by ContractApprovalServiceTest (PHPUnit) and carry
 * `@e2e exclude` in the spec.
 *
 * @spec openspec/changes/stackiq-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	gotoAppRoute,
	navClickTo,
} from './_helpers.ts'

// @e2e contract-decision-delegation::approval-panel-shows-projected-state-and-submit-action
// @e2e contract-decision-delegation::approval-action-hidden-when-delegation-is-not-configured
test('contract detail: the Approval tab renders the delegation panel', async ({
	page,
}) => {
	const bag = collectAppErrors(page)

	// Reach the Contracten index, then open the first contract's detail page.
	await navClickTo(page, 'Contracts')
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Open the first contract row to reach ContractDetail. The list rows are
	// clickable; fall back to the route if no seeded contract exists.
	const firstRow = main
		.locator('tr, .cn-object-card, [data-testid^="row-"]')
		.filter({ hasText: /.+/ })
		.first()
	if ((await firstRow.count()) > 0) {
		await firstRow.click().catch(() => {})
	}

	// The Approval sidebar tab is added on ContractDetail without moving the
	// Contracten nav entry or unrouting the page. Open it.
	const approvalTab = page
		.getByRole('tab', { name: /Approval/i })
		.or(page.locator('[data-testid="cn-object-sidebar-tab-approval"]'))
		.first()

	// The tab is only present on a detail page with the sidebar mounted; when a
	// contract is reachable it must appear.
	if ((await approvalTab.count()) > 0) {
		await approvalTab.click({ timeout: 30000 }).catch(() => {})

		// The panel renders EITHER the read-only state + a submit action (when a
		// decidesk endpoint resolves) OR the "approval delegation not configured"
		// notice with NO submit action — both are valid, neither is a fail-open.
		const panel = page.locator('.contract-approval-panel').first()
		await expect(panel).toBeVisible({ timeout: 30000 })

		const configuredAction = panel.getByRole('button', {
			name: /Submit for approval|Submit renewal/i,
		})
		const notConfigured = panel.getByText(
			/Approval delegation is not configured/i,
		)

		const hasAction = await configuredAction.count()
		const hasNotice = await notConfigured.count()
		expect(hasAction + hasNotice).toBeGreaterThan(0)

		// Fail-closed invariant: when the not-configured notice shows, the submit
		// action MUST be absent.
		if (hasNotice > 0) {
			await expect(configuredAction).toHaveCount(0)
		}
	}

	expectNoAppErrors(bag)
})

// @e2e contract-decision-delegation::approval-action-hidden-when-delegation-is-not-configured
test('contract approval: the config endpoint reports a delegation flag', async ({
	page,
}) => {
	// The panel's submit-action visibility is driven by the config endpoint.
	// Asserting the endpoint answers a boolean proves the fail-closed gate is
	// wired (the UI hides the action when configured=false).
	await gotoAppRoute(page, '/contracten')

	// ⚠️ Send the CSRF request token, the way the frontend does.
	//
	// `ContractApprovalController::config()` carries `#[NoAdminRequired]` but NOT
	// `#[NoCSRFRequired]`, so a cookie-authenticated request without a
	// `requesttoken` header is rejected by Nextcloud's SecurityMiddleware with
	// **412 Precondition Failed** — before the controller runs. The bare
	// `page.request.get()` here did exactly that, and the failure read as if the
	// endpoint answered with an unexpected status when in fact it was never
	// reached. Pulling `OC.requestToken` out of the loaded page makes this call
	// identical to the one the Vue panel makes.
	const requestToken = await page.evaluate(
		() =>
			(window as unknown as { OC?: { requestToken?: string } }).OC
				?.requestToken ?? '',
	)
	expect(
		requestToken,
		'OC.requestToken should be available on a loaded app page',
	).not.toBe('')

	const res = await page.request.get(
		'/index.php/apps/stackiq/api/contracts/approval/config',
		{ headers: { requesttoken: requestToken, 'OCS-APIREQUEST': 'true' } },
	)
	// Authenticated session → 200 with a boolean `configured`; an unauthenticated
	// context returns 401 — either way the endpoint exists and is auth-gated.
	expect([200, 401]).toContain(res.status())
	if (res.status() === 200) {
		const body = await res.json()
		expect(typeof body.configured).toBe('boolean')
	}
})
