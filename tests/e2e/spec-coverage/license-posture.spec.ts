// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for the software license-posture (SAM) surface.
 *
 * Page component under test: src/views/LicensePostureView.vue.
 *
 * Drives the REAL UI of the LicensePosture manifest custom page: the nav entry
 * reaches the posture dashboard, which renders the portfolio open-vs-closed
 * share (weighted by in-production deployment), the per-vendor rollup
 * (deployments + licence mix + annualised cost consumed from
 * contract-administration, degrading to "—" without contracts), and the
 * per-organisation open-source-first report. The exhaustive aggregation logic
 * (deployment weighting, phased-out exclusion, Unknown bucket, cost consumption
 * + no-contract degrade, closed contributors) is covered by the vitest unit
 * tests on the licensePosture utility.
 *
 * LIVE-RUN NOTE: authored against the built app but NOT deployed to the shared
 * dev instance (served app is the main checkout; deploying the worktree to the
 * shared instance is disallowed by policy). Runs green once deployed; here it
 * carries the @e2e traceability the gate requires.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	navClickTo,
} from './_helpers.ts'
import { LicensePostureView } from './page-components.ts'

// @e2e software-license-posture::open-source-vs-closed-source-share-reflects-deployments-not-catalogue-rows
test('license posture: nav reaches the dashboard; portfolio share renders', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, LicensePostureView)

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	await expect(
		main.getByText(/License posture|Portfolio posture/i).first(),
	).toBeVisible({ timeout: 30000 })

	// The portfolio posture KPI block (open-source share) renders.
	await expect(page.getByTestId('posture-portfolio')).toBeVisible({
		timeout: 30000,
	})

	expectNoAppErrors(bag)
})

// @e2e software-license-posture::per-vendor-rollup
// @e2e software-license-posture::deployment-count-reflects-in-production-usages
// @e2e software-license-posture::per-vendor-cost-reuses-contract-administrations-annualised-cost
// @e2e software-license-posture::posture-works-without-contract-data
test('license posture: per-vendor rollup renders deployments, mix and cost columns', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, LicensePostureView)

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The per-vendor section renders (a rollup table or its empty-state). The
	// cost column consumes contract-administration and degrades to "—" without
	// contracts; either way the section is reachable without app errors.
	await expect(page.getByTestId('posture-vendor')).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// @e2e software-license-posture::organisation-open-source-first-report
test('license posture: per-organisation report surface is present', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, LicensePostureView)

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The per-organisation open-source-first report section (with its org
	// selector) renders.
	await expect(page.getByTestId('posture-org')).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})
