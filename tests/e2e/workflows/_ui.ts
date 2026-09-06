import type { Locator, Page } from '@playwright/test'

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Shared UI driving helpers for the deep CRUD-persistence workflows.
 *
 * All navigation goes through the real app-navigation (CLICK, not deep-link
 * goto — the shared shell occasionally resets a deep-link to the Dashboard),
 * the cn-support-dialog is dismissed before any interaction, and every action
 * is performed against rendered DOM (CnIndexPage "Add ..." button, the per-row
 * NcActions menu with View/Edit/Copy/Delete, the create/edit form fields and
 * the delete-confirm dialog). No Vue `$data` / `__vue__` patching.
 */
import { expect } from '@playwright/test'

// `gotoAppRoute` is re-exported alongside `navClickTo` because not every
// manifest page has a navigation entry: `/contactpersonen` is routable but was
// deliberately dropped from the menu when contact identity moved to the
// Nextcloud addressbook, so for that page the route IS the user's real path.
export {
	APP_MAIN,
	collectAppErrors,
	dismissSupportDialog,
	expectNoAppErrors,
	gotoAppRoute,
	navClickTo,
} from '../spec-coverage/_helpers.ts'

/** The CnIndexPage main content region. */
export function indexMain(page: Page): Locator {
	return page.locator('main').first()
}

/** Switch the CnIndexPage to Cards view (cards carry the per-item Actions menu). */
export async function showCards(page: Page): Promise<void> {
	await indexMain(page).getByText('Cards', { exact: true }).first().click()
	await page.waitForTimeout(400)
}

/** Switch the CnIndexPage to Table view. */
export async function showTable(page: Page): Promise<void> {
	const toggle = indexMain(page).getByText('Table', { exact: true }).first()
	await toggle.waitFor({ state: 'visible', timeout: 15000 })
	// The themed checkbox-radio-switch label can swallow Playwright's synthetic
	// click (and a just-closed create dialog overlay may transiently intercept
	// it); fire a native click on the label element.
	await toggle.evaluate((el: HTMLElement) => el.click())
	await page.waitForTimeout(400)
}

/**
 * Count the rendered data rows. Prefers the "Showing N of M" header when the
 * CnIndexPage renders it; otherwise switches to Table view and counts the data
 * rows (the header row is excluded by requiring a <td>). Returns -1 if neither
 * is available.
 */
export async function listTotal(page: Page): Promise<number> {
	const main = indexMain(page)
	const txt = await main.innerText()
	const m = txt.match(/Showing\s+\d+\s+of\s+(\d+)/i)
	if (m) return Number(m[1])
	// Fallback: count table data rows (rows that contain a <td>).
	await showTable(page)
	return await main.locator('tr:has(td)').count()
}

/**
 * Locate a data ROW (Table view) carrying a unique token and open its NcActions
 * menu. Table view renders rows reliably regardless of card pagination, so this
 * is the robust path for find-by-token. After this returns the View/Edit/Copy/
 * Delete menuitems for THIS row are the ones in the shown popper.
 */
export async function openRowActions(page: Page, token: string): Promise<void> {
	await showTable(page)
	const row = indexMain(page).locator('tr', { hasText: token }).first()
	await expect(row).toBeVisible({ timeout: 15000 })
	await row.scrollIntoViewIfNeeded()
	await row.getByRole('button', { name: 'Actions' }).first().click()
	await page.waitForTimeout(500)
}

/**
 * Open the CnIndexPage create form via the primary "Add ..." button and return
 * the modal/dialog locator.
 *
 * `addLabel` accepts a RegExp as well as a string: CnIndexPage derives the
 * label as `'Add ' + schema.title`, and at least one schema title differs
 * between the repo and an already-deployed instance (OpenRegister skips
 * importing a schema whose deployed version is not older, and its
 * schemaContentDiffers() escape hatch never compares the title). `exact` is
 * meaningless for a RegExp, so it is only passed for a string.
 */
export async function openCreateDialog(
	page: Page,
	addLabel: string | RegExp,
): Promise<Locator> {
	const byName =
		typeof addLabel === 'string'
			? { name: addLabel, exact: true }
			: { name: addLabel }
	await indexMain(page).getByRole('button', byName).first().click()
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })
	return dialog
}

/**
 * Locate the card carrying a unique token and open its NcActions menu. After
 * this returns, the View/Edit/Copy/Delete menuitems for THIS card are the
 * visible ones (NcActions only keeps one popper's items visible at a time).
 */
export async function openCardActions(page: Page, token: string): Promise<void> {
	const card = indexMain(page).locator('[class*=card]', { hasText: token }).first()
	await expect(card).toBeVisible({ timeout: 15000 })
	await card.scrollIntoViewIfNeeded()
	await card.getByRole('button', { name: 'Actions' }).first().click()
	await page.waitForTimeout(500)
}

/**
 * Click a named action (View/Edit/Copy/Delete) in the CURRENTLY-OPEN NcActions
 * menu. Every card lazily renders its own NcActions popper, so a global
 * `getByRole('menuitem')` can match a different card's (hidden) menu. We scope
 * to the popper that is actually shown (`.v-popper__popper--shown`), falling
 * back to a visible role=menu, so the action always targets the card whose
 * Actions button we just clicked.
 */
export async function clickAction(
	page: Page,
	name: 'View' | 'Edit' | 'Copy' | 'Delete',
): Promise<void> {
	const openPopper = page.locator('.v-popper__popper--shown').last()
	const item = openPopper.getByRole('menuitem', { name, exact: true }).first()
	if (await item.count()) {
		await item.click()
		return
	}
	// Fallback: the visible menu only.
	await page
		.locator('[role="menu"]:visible')
		.last()
		.getByRole('menuitem', { name, exact: true })
		.first()
		.click()
}
