// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Two-route boot smoke check — gates the expensive e2e run on a cheap one.
 *
 * WHY THIS EXISTS
 * ---------------
 * A Vue 3 bundle can fail to boot while every other gate stays green. The known
 * case: `@nextcloud/l10n` v2 against nc-vue's pre-bundled dialogs, which call the
 * v3-only `getGettextBuilder().detectLanguage()`. That throws at import time and
 * leaves an EMPTY SHELL on every route — npm, ESLint, webpack and a byte-verified
 * deploy were all clean, and 37 minutes of e2e ran against a bundle that never
 * booted before a browser finally caught it.
 *
 * So this asserts the app MOUNTED, not that the server returned 200:
 *   - the app's own host element exists AND rendered real child content;
 *   - CnAppRoot did not render "Required apps are missing" — what a MISSING
 *     DEPENDENCY APP looks like. It does not error; it produces a plausible wall
 *     of red across the whole suite for a reason unrelated to the code.
 *   - no fatal page/console error was raised.
 *
 * ⚠️ This is a Playwright PROJECT rather than a standalone node script on
 * purpose. A standalone script has to solve authentication itself, and the
 * committed `tests/e2e/.auth/admin.json` is a dead session against whatever
 * instance last generated it — an unauthenticated load returns **401**, whose
 * symptom (`#stackiq` absent, ZERO js errors) is indistinguishable at a
 * glance from a genuinely dead bundle. Running as a project reuses
 * `globalSetup`, which logs in fresh against the configured base URL.
 *
 * Run:  npx playwright test --project smoke
 */

import { expect, test } from '@playwright/test'

const ROUTES = [
	{ name: 'app root', path: '/index.php/apps/stackiq/' },
	{
		name: 'organisations sub-route',
		path: '/index.php/apps/stackiq/organisaties',
	},
]

for (const route of ROUTES) {
	test(`the app mounts on the ${route.name}`, async ({ page }) => {
		const fatal: string[] = []
		page.on('pageerror', (e) => fatal.push(`pageerror: ${e.message}`))
		page.on('console', (m) => {
			if (m.type() === 'error') fatal.push(`console.error: ${m.text()}`)
		})

		const response = await page.goto(route.path, {
			waitUntil: 'domcontentloaded',
		})
		expect(response?.status(), `HTTP status for ${route.path}`).toBeLessThan(400)

		// The host element is emitted by templates/index.php. Its ABSENCE means we
		// are not on the app page at all (login redirect / 401), which must not be
		// reported as a boot failure.
		const host = page.locator('#stackiq')
		await expect(
			host,
			'app host element #stackiq is missing — are we authenticated?',
		).toBeAttached({ timeout: 30_000 })

		// A mounted app replaces the empty host with real DOM. Host present but
		// childless is the boot-killer shape.
		await expect
			.poll(async () => await host.evaluate((el) => el.children.length), {
				message:
					'#stackiq rendered NO children — the bundle did not mount (empty shell)',
				timeout: 30_000,
			})
			.toBeGreaterThan(0)

		await expect(
			page.getByText(/Required apps are missing/i),
			'CnAppRoot reports a missing dependency app '
				+ '— fix the INSTANCE; do not read the resulting e2e failures as migration defects',
		).toHaveCount(0)

		const boot = fatal.filter((e) =>
			/detectLanguage is not a function|ChunkLoadError|Failed to fetch dynamically imported|Cannot read properties of undefined \(reading 'toString'\)/i.test(
				e,
			),
		)
		expect(boot, `fatal boot errors on ${route.path}`).toEqual([])
	})
}
