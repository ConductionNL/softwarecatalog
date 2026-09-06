import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for GEMMA faceted search.
 *
 * Surfaces under test:
 *   lib/Controller/FacetController.php   GET /api/facets/{schema}
 *   lib/Service/FacetService.php         aggregation + per-caller cache
 *   src/views/FacetedCatalogIndexView.vue  manifest pages `Modules` (/modules)
 *                                          and `Diensten` (/diensten)
 *
 * ⚠️ SCOPE OF THIS FILE — READ BEFORE ADDING TO IT.
 *
 * The scenarios about facet VALUES (counts per referenceComponent, OR-within-
 * a-dimension, AND-across-dimensions, URL round-tripping of a selection, saved
 * views) are NOT covered here, and not because they are hard: the fixtures
 * cannot be built through the object API. Measured on a live instance —
 * POSTing a module with
 *     {"standardVersions":["StUF-ZKN-x"],"referenceComponents":["RC-x"]}
 * returns 200 with BOTH arrays silently emptied (`"standardVersions":[]`,
 * `"referenceComponents":[]`). They are relation fields; OpenRegister drops
 * bare strings without erroring. Seeding them needs real `element` objects in
 * the AMEF register plus `relation` rows, which is a fixture layer this file
 * deliberately does not fake — a facet test seeded with data the product
 * cannot produce would prove nothing.
 *
 * So this file covers the facet CONTRACT and the panel's presence, and leaves
 * the value-dependent scenarios uncovered and counted. That is the honest
 * split; see the PR for the two scenarios that are unimplemented outright.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	navClickTo,
} from './_helpers.ts'

const FACETS = '/index.php/apps/stackiq/api/facets'
/** The four GEMMA dimensions the endpoint must always describe. */
const DIMENSIONS = [
	// Wire names: FacetController and FacetService declare these four, and they
	// are the query parameters and response keys of the endpoint. They move as a
	// set or not at all — translating only `standard` made the frontend ask for
	// `standard[]` from a backend that reads `standard[]`, and filtering silently
	// returned everything.
	'referenceComponent',
	'standard',
	'applicationService',
	'domain',
] as const

/** Unique per run so the cache — keyed by params — is cold on first use. */
const TOKEN = `facet${RUN_ID.replace(/[^a-z0-9]/gi, '')}`

let ctx: APIRequestContext
let config: VoorzieningenConfig
const seeded: string[] = []

test.beforeAll(async () => {
	ctx = await newApiContext()
	config = await resolveConfig(ctx)
	// Two modules whose names contain TOKEN, so a text query can select
	// exactly them out of whatever else the instance holds.
	for (const n of [1, 2]) {
		seeded.push(
			await createObject(ctx, config.register, 'module', {
				name: `${TOKEN} module ${n}`,
			}),
		)
	}
})

test.afterAll(async () => {
	if (!ctx || !config) return
	for (const id of seeded) {
		await deleteObject(ctx, config.register, 'module', id)
	}
	await ctx.dispose()
})

// @e2e gemma-faceted-search::facet-response-covers-all-four-gemma-dimensions
test('facets: the response carries all four GEMMA dimensions, empty ones as [] not omitted', async () => {
	// ⚠️ A CACHE-COLD parameter set, and that is load-bearing.
	//
	// The unparameterised `GET /api/facets/module` is served from an entry the
	// product never invalidates (the facet cache is TTL-only), so it can answer
	// with a count — and a response shape — that predates the running code.
	// Measured: `findAll` saw 3 modules while the unparameterised call returned
	// `{"cached":true,"totalMatched":1}`. A unique `search` term forces a fresh
	// computation, so this assertion is made against live output.
	//
	// The `cached === false` guard below is not decoration: without it this test
	// would silently degrade into "some earlier response had four dimensions".
	const res = await ctx.get(
		`${FACETS}/module?search=${encodeURIComponent(`${TOKEN}-dims`)}`,
	)
	expect(res.status(), `GET ${FACETS}/module returned ${res.status()}`).toBe(200)
	const body = await res.json()
	expect(
		body?._meta?.cached,
		'this assertion needs a freshly computed response, but got a cached one',
	).toBe(false)

	// This is the load-bearing half of the scenario: "a dimension with no
	// matching objects MUST be present as an empty array, not omitted". On this
	// instance the GEMMA link fields are unpopulated, so every dimension IS
	// empty — which makes this the exact condition the scenario describes,
	// rather than a weaker version of it.
	for (const dim of DIMENSIONS) {
		expect(
			Object.hasOwn(body, dim),
			`dimension "${dim}" is missing from the response`,
		).toBe(true)
		expect(
			Array.isArray(body[dim]),
			`dimension "${dim}" is not an array: ${JSON.stringify(body[dim])}`,
		).toBe(true)
	}
	// And no extra top-level dimension keys crept in beyond the four + _meta.
	expect(Object.keys(body).sort()).toEqual(
		[...DIMENSIONS].sort().concat('_meta').sort(),
	)
})

// @e2e gemma-faceted-search::unsupported-schema-is-rejected
test('facets: an unsupported schema is rejected with 400 naming the supported ones', async () => {
	const res = await ctx.get(`${FACETS}/contract`)
	expect(
		res.status(),
		`GET ${FACETS}/contract returned ${res.status()} — expected 400`,
	).toBe(400)

	const body = await res.json()
	const message = String(body?.message ?? '')
	// The scenario requires the error to NAME the supported schemas, not merely
	// to reject — so both names are asserted, not just a non-2xx.
	//
	// ⚠️ The supported set is `FacetService::SUPPORTED_SCHEMAS = ['module',
	// 'catalogService']`. This assertion used to look for `dienst`, the pre-#518 Dutch
	// slug, and so did the 200 control below — a slug rename moved the API and
	// left the test naming a schema the service has never heard of.
	expect(
		message,
		`error message did not name the supported schemas: ${message}`,
	).toMatch(/module/)
	// `catalogService`, not `/service/`. #958 namespaced the slug and this
	// regex is case-sensitive, so it stopped matching the very name it is here
	// to check — the message read "Supported schemas: module, catalogService"
	// and the assertion still failed.
	expect(message).toMatch(/catalogService/)

	// The supported set is also machine-readable, and must be exactly the two.
	// ⚠️ `Array.prototype.sort()` sorts IN PLACE and returns the sorted array,
	// so the expected literal has to be sorted too — comparing a sorted actual
	// against `['catalogService', 'module']` could never have held whichever names the
	// service used. Copy before sorting so the response body is not mutated.
	// SORTED, and the rename moved which one comes first. `'catalogService'`
	// sorts before `'module'` (ASCII 'c' < 'm'), where the pre-#958 `'service'`
	// sorted after it. This literal kept the old ORDER with the new NAME, so it
	// could not have held either — it was simply never reached, because the
	// assertion above threw first.
	expect([...(body?.supportedSchemas ?? [])].sort()).toEqual([
		'catalogService',
		'module',
	])

	// Control: the same endpoint shape with a SUPPORTED schema is a 200, so the
	// 400 above is about the schema and not about the route being broken.
	const ok = await ctx.get(`${FACETS}/catalogService`)
	expect(ok.status(), `GET ${FACETS}/catalogService returned ${ok.status()}`).toBe(
		200,
	)
})

// ⚠️ `no-text-query-returns-facets-over-the-full-rbac-scoped-set` IS DELIBERATELY
// NOT CLAIMED HERE, and it is not an oversight.
//
// The scenario needs the UNPARAMETERISED aggregate, and that is exactly the
// cache entry the product never invalidates. Measured during the first run of
// this file: `findAll` saw 3 modules while `GET /api/facets/module` answered
// `{"cached":true,"totalMatched":1}` — the entry had been populated when the
// instance held one module and did not move when two more were created. The
// assertion `totalMatched === visibleModules` therefore fails for a REAL
// product reason (filed: facet cache is TTL-only, no invalidation path).
//
// Three ways to make it green were available and all three were rejected:
// waiting out the 1800 s TTL (a timing-dependent test), asserting `>=` instead
// of `===` (an assertion that cannot fail), or adding a cache-busting param
// (which makes it a different scenario — a FILTERED aggregate). Leaving the
// scenario uncovered and counted is the honest outcome; it becomes coverable
// the moment invalidation exists.

// @e2e gemma-faceted-search::text-query-narrows-facet-counts
test('facets: a text query narrows the aggregated set', async () => {
	// Both comparands use CACHE-COLD parameter sets. The unparameterised entry
	// is unusable as a baseline here (see the block above), so "narrows" is
	// asserted between a broad query and a strictly-narrower one, both of which
	// are computed fresh and therefore both accurate.
	const broad = await ctx.get(
		`${FACETS}/module?search=${encodeURIComponent(TOKEN)}`,
	)
	expect(
		broad.status(),
		`GET with the broad search returned ${broad.status()}`,
	).toBe(200)
	const totalBroad = (await broad.json())?._meta?.totalMatched
	expect(
		totalBroad,
		`the run token matched ${totalBroad}, expected the 2 seeded modules`,
	).toBe(2)

	// One character more specific — matches exactly one of the two.
	const narrow = await ctx.get(
		`${FACETS}/module?search=${encodeURIComponent(`${TOKEN} module 1`)}`,
	)
	expect(narrow.status()).toBe(200)
	const totalNarrow = (await narrow.json())?._meta?.totalMatched
	expect(
		totalNarrow,
		`the narrower query matched ${totalNarrow}, expected 1`,
	).toBe(1)

	// Strictly fewer: that is what "narrows" means, and both numbers were
	// computed rather than served stale.
	expect(totalBroad).toBeGreaterThan(totalNarrow)

	// A term matching nothing narrows all the way to zero, and still returns
	// all four dimensions rather than erroring.
	const miss = await ctx.get(
		`${FACETS}/module?search=${encodeURIComponent(TOKEN)}zzzznomatch`,
	)
	expect(miss.status()).toBe(200)
	const missBody = await miss.json()
	expect(missBody?._meta?.totalMatched).toBe(0)
	for (const dim of DIMENSIONS) {
		expect(
			Array.isArray(missBody[dim]),
			`dimension "${dim}" absent on an empty result`,
		).toBe(true)
	}
})

// ⚠️ `repeated-identical-facet-request-is-served-from-cache` IS NOT CLAIMED HERE.
//
// It passed locally and FAILED ON CI, and the difference is the environment,
// not the code. The facet cache is `ICacheFactory::createDistributed(...)`,
// which degrades to a NULL cache when Nextcloud has no memcache backend
// configured — nothing is stored and `_meta.cached` is therefore always false.
//
// Measured on both sides:
//   dev rig  — `occ config:system:get memcache.local` -> \OC\Memcache\APCu,
//              APCu present; the flag flips false -> true reliably.
//   CI       — the shared workflow configures no memcache at all (zero
//              mentions of memcache/apcu in the job log); both calls returned
//              `cached: false` and the assertion failed on the first run and
//              its retry.
//
// So on the instance where this suite actually runs, the scenario has no
// observable behaviour. The tempting fixes are both wrong: a `test.skip` guard
// on "is caching available" would never be false on CI, so it would credit
// coverage that never executes; and asserting only that `cached` is a boolean
// is an assertion that cannot fail. Left uncovered and counted until the CI
// instance configures a cache backend.

// @e2e gemma-faceted-search::facet-panel-renders-alongside-the-existing-index-page-toolbar
test('facets: the GEMMA panel renders beside the search box on the module index', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Applications')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The facet sidebar is present…
	const sidebar = page.locator('.cn-facet-sidebar').first()
	await expect(sidebar, 'the GEMMA facet sidebar did not render').toBeVisible({
		timeout: 30000,
	})
	await expect(sidebar.locator('.cn-facet-sidebar__title')).toContainText(/GEMMA/i)

	// …and it lists all four dimensions as filter groups.
	await expect(sidebar.locator('.cn-facet-sidebar__group')).toHaveCount(
		DIMENSIONS.length,
	)

	// …AND the pre-existing toolbar still works alongside it, which is the
	// second half of the scenario ("the existing free-text search box ... MUST
	// continue to render and function unchanged").
	const search = page.locator('.faceted-catalog-index__search').first()
	await expect(search, 'the free-text search box disappeared').toBeVisible()
	await search.getByRole('textbox').first().fill(TOKEN)
	// The list re-fetches and settles on this run's seeded modules.
	await expect(main.getByText(`${TOKEN} module 1`).first()).toBeVisible({
		timeout: 30000,
	})

	expectNoAppErrors(bag)
})
