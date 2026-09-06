// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares three:
 *
 *   smoke    — two-route boot check (tests/e2e/smoke/app-mounts.spec.ts).
 *   chromium — the regression suite. This is the one CI wants.
 *   visual   — pixel-diff baselines. Its own header says the committed PNGs
 *              are host-font/GPU specific and "a CI Linux runner will not
 *              byte-match a dev-container baseline", so it CANNOT pass here.
 *
 * Letting the root config be picked would therefore run a project documented
 * as unable to pass on a CI runner. Rather than delete or weaken `visual`,
 * `playwright-test-path: tests/e2e` in the caller makes the workflow's FIRST
 * config lookup hit this file. The root config is untouched and stays the
 * entry point for local runs and `--project visual`.
 *
 * WHY `smoke` IS KEPT IN CI (a deliberate choice, not a copy)
 * ----------------------------------------------------------
 * It is two tests and a few seconds, and it is the only thing in the suite
 * that distinguishes "the bundle never mounted" from "the product is broken".
 * A Vue 3 bundle that throws at import time produces an empty shell on every
 * route — which surfaces as ~50 selector timeouts that all read like product
 * defects. Projects run in declaration order under `workers: 1`, so smoke
 * runs first and labels that failure mode before the regression suite spends
 * the budget reproducing it 50 times. The `chromium` project's testIgnore
 * excludes the smoke directory, so nothing runs twice.
 *
 * (Deliberately spelled out in prose: a glob written literally here would
 * contain the `*` + `/` sequence that CLOSES this block comment, which turns
 * the remaining prose into executable code. That is exactly how the first
 * version of this file died — `ReferenceError: smoke is not defined`.)
 *
 * The report/output paths differ from the root config deliberately: the
 * workflow uploads `server/apps/stackiq/playwright-report/` and
 * `server/apps/stackiq/test-results/`, so on CI the artefacts must
 * land at the APP ROOT. The root config's bare `reporter: 'list'` produces no
 * HTML report at all, which would make the upload step silently attach an
 * empty artifact (`if-no-files-found: ignore`) — a failing run with nothing
 * to read.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		[
			'html',
			{
				open: 'never',
				outputFolder: path.join(APP_ROOT, 'playwright-report'),
			},
		],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'smoke',
			testMatch: /smoke\/.*\.spec\.ts$/,
			use: { ...devices['Desktop Chrome'] },
		},
		{
			name: 'chromium',
			testIgnore: ['**/visual/**', '**/smoke/**'],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
