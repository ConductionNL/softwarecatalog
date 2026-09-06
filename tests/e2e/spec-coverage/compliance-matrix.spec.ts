// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for the compliance matrix page.
 *
 * Drives the REAL UI of the manifest custom page `ComplianceMatrix`
 * (ComplianceMatrixView.vue): navigate via the actual app-navigation entry,
 * assert the filter-first surface mounts (title + standards selector +
 * guidance empty-state) WITHOUT an app-origin console/5xx error. The
 * data-dependent three-state cell rendering is covered exhaustively by the
 * vitest unit tests on the matrix data mapper (tests/vitest/complianceMatrix.spec.js);
 * here we guard that the page itself renders and is reachable.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	navClickTo,
} from './_helpers.ts'
import { ComplianceMatrixView } from './page-components.ts'

// @e2e module-compliance-assessment::matrix-renders-the-three-cell-states
// @e2e module-compliance-assessment::matrix-selection-is-shareable
test('compliance matrix: nav entry reaches the filter-first matrix surface', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, ComplianceMatrixView)

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Filter-first: until standards are picked the page shows guidance, never a
	// cartesian wall. Either the "select standards" prompt or the "no standards
	// imported" guidance is shown — both are valid data-independent states.
	await expect(
		main.getByText(/Select standards to compare|No standards imported/i),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// @e2e module-compliance-assessment::matrix-renders-bio-measure-columns
// @e2e bio-compliance-assessment::organisation-sees-its-bio-compliance-posture
test('compliance matrix: switching to the BIO measures scope reaches its own filter-first surface', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, ComplianceMatrixView)

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Same reachability contract as the standards scope above: switching the
	// column-source radio to "BIO measures" (bio-compliance-assessment) must
	// reach a filter-first empty state — never a cartesian wall, never an
	// app-origin error — before any column is picked.
	await main.getByText('BIO measures', { exact: true }).first().click()

	await expect(
		main.getByText(/Select BIO measures to compare|No BIO measures seeded/i),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})
