import type { APIRequestContext } from '@playwright/test'
import type { Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for catalog ratings & reviews.
 *
 * Surfaces under test:
 *   src/components/reviews/ReviewsPanel.vue     body widget `md-reviews` on
 *                                               ModuleDetail (/modules/:id)
 *   src/modals/SubmitReviewModal.vue            "Write a review"
 *   src/views/settings/sections/ModerationQueue.vue
 *                                               reused for type="software-review"
 *                                               at /settings/admin/stackiq
 *   manifest page `Reviews` (/reviews)          the reviews index
 *
 * The moderation lifecycle is driven END TO END through the UI: submit in the
 * module panel, approve/reject in the admin queue, then re-read the panel. The
 * aggregate is *not* re-derived by the test — it is read off the rendered
 * panel, so a change that stops the panel consuming the aggregate fails here.
 *
 * Two scenarios are asserted at the HTTP layer rather than the DOM, and both
 * are genuinely unreachable through the shipped UI, not shortcuts:
 *   - "an unauthenticated request cannot create a review": the modal is only
 *     rendered to a logged-in user, so the anonymous case has no UI at all.
 *   - "a client-supplied author is ignored": SubmitReviewModal never sends an
 *     `auteur` field, so the rogue payload cannot be produced by any click.
 * Playwright's APIRequestContext is still the browser's own HTTP stack, and
 * every probe below prints its STATUS CODE into the assertion message.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
import { expect, request as playwrightRequest, test } from '@playwright/test'
import {
	BASE_URL,
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import {
	APP_BASE,
	APP_MAIN,
	APP_SHELL,
	collectAppErrors,
	dismissSupportDialog,
	dismissWalkthrough,
	expectNoAppErrors,
	navClickTo,
} from './_helpers.ts'

const MODULE_NAME = `Review subject ${RUN_ID}`
/** Unique per test so the moderation queue row this test acts on is its own. */
const title = (suffix: string): string => `Review ${suffix} ${RUN_ID}`

let ctx: APIRequestContext
let config: VoorzieningenConfig
// OpenRegister's object API returns the object's UUID as `id` (verified on the
// live instance: `id` === `@self.id` === a v4 uuid; there is no separate
// `uuid` key). The detail ROUTE takes that same value, so `createObject`'s
// return value is directly the deep-link segment — no lookup needed.
let moduleUuid = ''

test.beforeAll(async () => {
	ctx = await newApiContext()
	config = await resolveConfig(ctx)
	moduleUuid = await createObject(ctx, config.register, 'module', {
		name: MODULE_NAME,
	})
})

test.afterAll(async () => {
	if (!ctx || !config) return
	for (const schema of ['software-review', 'module']) {
		const rows = await findAll(ctx, config.register, schema)
		for (const row of rows) {
			if (JSON.stringify(row).includes(RUN_ID)) {
				const id = String(
					row.id
						?? (row as { '@self'?: { id?: string } })['@self']?.id
						?? '',
				)
				if (id) await deleteObject(ctx, config.register, schema, id)
			}
		}
	}
	await ctx.dispose()
})

/**
 * A genuinely UNAUTHENTICATED APIRequestContext.
 *
 * ⚠️ `request.newContext({ baseURL })` is NOT anonymous under this config.
 * Playwright merges the project's `use` options into the new context, and this
 * project sets `use.storageState` to the admin cookie jar — so a context
 * created "with no credentials" silently runs AS ADMIN. Measured on this rig:
 * `GET /ocs/v2.php/cloud/user` from such a context returned **200 with
 * `"id":"admin"`**, and an "anonymous" `POST /api/reviews` returned **202
 * Accepted**. Passing `storageState: undefined` explicitly returns **401
 * `Current user is not logged in`** for both.
 *
 * This is a control that fails silently in the dangerous direction: a test
 * asserting "unauthenticated is rejected" would be firing an AUTHENTICATED
 * request and could only pass by accident. It did, briefly — an earlier draft
 * of this file "passed" on a 400 `empty payload` that had nothing to do with
 * authentication at all.
 *
 * So the helper asserts its own premise before returning: if the context is
 * not anonymous, it fails here rather than letting a caller draw a conclusion
 * from it.
 */
async function newAnonymousContext(): Promise<APIRequestContext> {
	const anon = await playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: undefined,
		extraHTTPHeaders: {},
	})
	const whoami = await anon.get('/ocs/v2.php/cloud/user?format=json', {
		headers: { 'OCS-APIREQUEST': 'true' },
	})
	expect(
		whoami.status(),
		`the "anonymous" context is authenticated (whoami returned ${whoami.status()}) — `
			+ 'every anonymous assertion made through it would be meaningless',
	).toBe(401)
	return anon
}

/** Open the seeded module's detail page and wait for the reviews panel. */
async function openModuleReviews(page: Page): Promise<void> {
	await page.goto(`${APP_BASE.replace(/\/$/, '')}/modules/${moduleUuid}`, {
		waitUntil: 'domcontentloaded',
	})
	await page
		.locator(APP_SHELL)
		.first()
		.waitFor({ state: 'attached', timeout: 30000 })
	await page
		.locator(APP_MAIN)
		.first()
		.waitFor({ state: 'visible', timeout: 30000 })
	await dismissSupportDialog(page)
	await dismissWalkthrough(page)
	await expect(page.locator('.reviews-panel').first()).toBeVisible({
		timeout: 30000,
	})
}

/** Submit a review through the real modal. */
async function submitReview(
	page: Page,
	reviewTitle: string,
	rating: string,
): Promise<void> {
	await page
		.getByRole('button', { name: 'Write a review', exact: true })
		.first()
		.click()
	const dialog = page
		.getByRole('dialog')
		.filter({ hasText: 'Write a review' })
		.first()
	await expect(dialog).toBeVisible({ timeout: 15000 })
	await dialog
		.getByRole('textbox', { name: /^Title/ })
		.first()
		.fill(reviewTitle)
	// `.vs__search` no longer identifies the input on its own: the component
	// library now also puts that class on a wrapper, so `.first()` resolved to
	//   <div class="input-field input-field--label-outside vs__search">
	// and `fill()` refused it with "Element is not an <input>, <textarea>,
	// <select> or [contenteditable]". Demand an actual input, whether the class
	// sits on it or on an ancestor, so this survives the markup moving again.
	const rater = dialog.locator('input.vs__search, .vs__search input').first()
	await rater.click()
	await page
		.locator('.vs__dropdown-option', { hasText: new RegExp(`^${rating}$`) })
		.first()
		.click()
	await dialog
		.getByRole('textbox', { name: /^Testimonial/ })
		.first()
		.fill(`Body for ${reviewTitle}`)
	await dialog.getByRole('button', { name: 'Submit review', exact: true }).click()
	await expect(dialog).toBeHidden({ timeout: 30000 })
}

/** Open the admin settings page and wait for the review moderation queue. */
async function openReviewQueue(page: Page) {
	await page.goto('/index.php/settings/admin/stackiq', {
		waitUntil: 'domcontentloaded',
	})
	const queue = page
		.locator('section, .settings-section')
		.filter({ hasText: 'Review moderation' })
		.first()
	await expect(queue).toBeVisible({ timeout: 30000 })
	return queue
}

/** Act on one named row in a moderation queue. */
async function moderate(
	page: Page,
	reviewTitle: string,
	action: 'Approve' | 'Reject',
): Promise<void> {
	const queue = await openReviewQueue(page)
	const row = queue
		.locator('li.moderation-item')
		.filter({ hasText: reviewTitle })
		.first()
	await expect(row, `no pending queue row titled "${reviewTitle}"`).toBeVisible({
		timeout: 30000,
	})
	await row.getByRole('button', { name: action, exact: true }).click()
	// The row leaves the pending queue once the verdict lands.
	await expect(row).toBeHidden({ timeout: 30000 })
}

// @e2e catalog-ratings::an-authenticated-catalog-user-can-submit-a-review
// @e2e catalog-ratings::a-submission-lands-pending-and-is-not-yet-public
test('reviews: an authenticated submission lands pending and is not yet public', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	const reviewTitle = title('pending')

	await openModuleReviews(page)
	await submitReview(page, reviewTitle, '8')

	// NOT yet public: the panel re-fetches the aggregate on submit, and the
	// new review must not be in it.
	const panel = page.locator('.reviews-panel').first()
	await expect(
		panel.locator('.reviews-panel__list').getByText(reviewTitle),
	).toHaveCount(0)

	// It IS waiting in the admin moderation queue.
	const queue = await openReviewQueue(page)
	const row = queue
		.locator('li.moderation-item')
		.filter({ hasText: reviewTitle })
		.first()
	await expect(row, `submitted review is not in the pending queue`).toBeVisible({
		timeout: 30000,
	})

	// NOTE: the queue row shows only the title. `moderationItemSubtitle()`
	// (src/utils/moderationItem.js) picks its subtitle from
	// email/contactEmail/url/website/beschrijving/description — a `beoordeeling`
	// carries none of those, so the subtitle is ''. The server-bound author is
	// therefore NOT observable here; it is asserted on the Reviews index below,
	// which does render the `auteur` column.

	expectNoAppErrors(bag)
})

// @e2e catalog-ratings::admin-approval-makes-the-review-public
// @e2e catalog-ratings::aggregate-reflects-only-approved-reviews
// @e2e catalog-ratings::an-approved-review-is-publicly-readable
test('reviews: admin approval publishes the review and moves the aggregate', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	const reviewTitle = title('approve')

	await openModuleReviews(page)
	// Before: this module has no approved review, so the average renders "—".
	await expect(page.locator('.reviews-panel__average-value--empty')).toBeVisible()

	await submitReview(page, reviewTitle, '9')
	await moderate(page, reviewTitle, 'Approve')

	// After approval the panel lists it and the aggregate counts it.
	await openModuleReviews(page)
	const panel = page.locator('.reviews-panel').first()
	await expect(
		panel.locator('.reviews-panel__list').getByText(reviewTitle).first(),
	).toBeVisible({ timeout: 30000 })
	await expect(
		panel.locator('.reviews-panel__average-value').first(),
	).toContainText('9')
	await expect(panel.locator('.reviews-panel__count')).toContainText('1 review')

	// Publicly readable: the aggregate endpoint is #[PublicPage], so an
	// ANONYMOUS context (no credentials at all) must see the approved review.
	const anon = await newAnonymousContext()
	const res = await anon.get(
		`/index.php/apps/stackiq/api/reviews/aggregate?subjectType=module&subjectId=${moduleUuid}`,
	)
	expect(res.status(), `anonymous aggregate returned ${res.status()}`).toBe(200)
	const body = await res.json()
	expect(
		JSON.stringify(body),
		'approved review absent from the anonymous aggregate',
	).toContain(reviewTitle)
	await anon.dispose()

	expectNoAppErrors(bag)
})

// @e2e catalog-ratings::admin-rejection-keeps-the-review-hidden
// @e2e catalog-ratings::a-rejected-review-is-not-publicly-readable
// @e2e catalog-ratings::a-pending-review-is-not-publicly-readable
test('reviews: a rejected review stays hidden, and so does a pending one', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	const rejected = title('reject')
	const pending = title('stillpending')

	// The REJECTED one is submitted through the real modal — that is the leg
	// this test is about. The still-PENDING one is seeded through the API:
	// it is a fixture (a second row that must stay invisible), not the
	// behaviour under test, and driving the modal twice in one page session
	// re-opens it with the previous submission's state still in the form.
	const seeded = await ctx.post('/index.php/apps/stackiq/api/reviews', {
		data: {
			review: {
				name: pending,
				rating: 3,
				longDescription: 'Still pending',
			},
			subjectType: 'module',
			subjectId: moduleUuid,
		},
	})
	expect(
		seeded.status(),
		`seeding the pending review returned ${seeded.status()}`,
	).toBeLessThan(300)

	await openModuleReviews(page)
	await submitReview(page, rejected, '2')

	await moderate(page, rejected, 'Reject')

	// Neither the rejected nor the still-pending review is in the panel.
	await openModuleReviews(page)
	const panel = page.locator('.reviews-panel').first()
	await expect(panel.getByText(rejected)).toHaveCount(0)
	await expect(panel.getByText(pending)).toHaveCount(0)

	// Nor in the anonymous (public) aggregate.
	const anon = await newAnonymousContext()
	const res = await anon.get(
		`/index.php/apps/stackiq/api/reviews/aggregate?subjectType=module&subjectId=${moduleUuid}`,
	)
	expect(res.status(), `anonymous aggregate returned ${res.status()}`).toBe(200)
	const text = await res.text()
	expect(text, 'rejected review leaked into the public aggregate').not.toContain(
		rejected,
	)
	expect(text, 'pending review leaked into the public aggregate').not.toContain(
		pending,
	)
	await anon.dispose()

	expectNoAppErrors(bag)
})

// @e2e catalog-ratings::aggregate-with-no-approved-reviews
test('reviews: a module with no approved reviews shows the empty aggregate, not a zero score', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	// A module of its own, so no other test's approval can reach it.
	const isolatedName = `Unreviewed module ${RUN_ID}`
	const uuid = await createObject(ctx, config.register, 'module', {
		name: isolatedName,
	})
	expect(uuid, 'isolated module fixture has no uuid').not.toBe('')

	await page.goto(`${APP_BASE.replace(/\/$/, '')}/modules/${uuid}`, {
		waitUntil: 'domcontentloaded',
	})
	await page
		.locator(APP_MAIN)
		.first()
		.waitFor({ state: 'visible', timeout: 30000 })
	await dismissSupportDialog(page)
	await dismissWalkthrough(page)

	const panel = page.locator('.reviews-panel').first()
	await expect(panel).toBeVisible({ timeout: 30000 })
	// The average is the em-dash placeholder — NOT "0/10".
	await expect(panel.locator('.reviews-panel__average-value--empty')).toBeVisible()
	await expect(panel.locator('.reviews-panel__average-value')).not.toContainText(
		'0/10',
	)
	await expect(panel.getByText('No reviews yet')).toBeVisible()

	expectNoAppErrors(bag)
})

// @e2e catalog-ratings::an-admin-moderates-pending-reviews-through-the-existing-queue-ui
// @e2e catalog-ratings::the-default-unparameterised-organisatie-moderation-path-is-unchanged
test('reviews: moderation reuses the ONE queue component, and the organisatie queue is unchanged', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await page.goto('/index.php/settings/admin/stackiq', {
		waitUntil: 'domcontentloaded',
	})

	// The review queue exists…
	const reviewQueue = page
		.locator('section, .settings-section')
		.filter({ hasText: 'Review moderation' })
		.first()
	await expect(reviewQueue).toBeVisible({ timeout: 30000 })
	// …rendered by the SAME component as the organisatie queue: both expose the
	// component's own "Refresh queue" affordance and its `moderation-list`/
	// empty-state markup. A second, bespoke review-moderation mechanism would
	// not carry these.
	await expect(
		reviewQueue.getByRole('button', { name: /Refresh queue/i }).first(),
	).toBeVisible()

	// The DEFAULT (organisatie) queue still renders alongside it, untouched.
	const orgQueue = page
		.locator('section, .settings-section')
		.filter({
			hasText:
				/Organisation moderation|Organisatie moderation|Pending registrations/i,
		})
		.first()
	await expect(
		orgQueue,
		'the default organisatie moderation queue disappeared',
	).toBeVisible({ timeout: 30000 })
	await expect(
		orgQueue.getByRole('button', { name: /Refresh queue/i }).first(),
	).toBeVisible()

	// And the unparameterised endpoint still answers for organisatie.
	const res = await ctx.get('/index.php/apps/stackiq/api/moderation/pending')
	expect(res.status(), `default moderation/pending returned ${res.status()}`).toBe(
		200,
	)

	expectNoAppErrors(bag)
})

// @e2e catalog-ratings::every-configured-column-resolves-to-a-real-schema-property
// @e2e catalog-ratings::the-stored-review-carries-the-authenticated-users-name
test("reviews index: each configured column renders this row's real value", async ({
	page,
}) => {
	const bag = collectAppErrors(page)

	// ⚠️ Asserting the column HEADINGS would test the manifest, not the render:
	// a column naming a property the `beoordeeling` schema does not have still
	// renders its heading perfectly — it is the CELL that comes back empty.
	// (That is exactly the failure the spec names: the page was configured with
	// `titel`/`score`/`datum`, none of which exist on the schema.) So this test
	// seeds a row with a DISTINCT value per configured column and asserts each
	// value reaches the page.
	const reviewTitle = title('columns')
	const created = await ctx.post('/index.php/apps/stackiq/api/reviews', {
		data: {
			review: {
				name: reviewTitle,
				rating: 6,
				longDescription: 'Column probe',
			},
			subjectType: 'module',
			subjectId: moduleUuid,
		},
	})
	expect(
		created.status(),
		`seed POST /api/reviews returned ${created.status()}: ${await created.text()}`,
	).toBeLessThan(300)

	await navClickTo(page, 'Reviews')
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Located BY THIS RUN'S UNIQUE TITLE, so a pre-existing review cannot
	// satisfy the assertion — no search box interaction needed, and none is
	// used: a search control whose selector drifts would turn a real coverage
	// failure into a locator timeout.
	const row = main
		.locator('tr, li, [class*="card"]')
		.filter({ hasText: reviewTitle })
		.first()
	await expect(row, `no Reviews row for "${reviewTitle}"`).toBeVisible({
		timeout: 30000,
	})

	// `naam` — the row's own title. `auteur` — bound server-side to the session
	// user. `waardering` — the rating we sent. `status` — `pending`, since the
	// submission has not been moderated. All four are the manifest's configured
	// columns; a key that resolved to nothing would leave its value absent.
	await expect(row).toContainText(reviewTitle)
	await expect(row).toContainText(/admin/i)
	await expect(row).toContainText('6')
	await expect(row).toContainText(/pending/i)

	expectNoAppErrors(bag)
})

// @e2e catalog-ratings::an-unauthenticated-request-cannot-create-a-review
test('reviews: an anonymous POST cannot create a review', async () => {
	// No UI path exists for this: the "Write a review" button is only rendered
	// inside the authenticated SPA. Asserted at the HTTP layer for that reason.
	const anon = await newAnonymousContext()
	// Body shape mirrors the modal's own `buildReviewSubmission()`:
	// `{ review: {...}, subjectType, subjectId }` — the controller signature is
	// `submit(array $review, string $subjectType, string $subjectId)`, so a
	// FLAT body is rejected with `{"message":"empty payload"}` before auth is
	// ever considered, which would make this test pass for the wrong reason.
	const res = await anon.post('/index.php/apps/stackiq/api/reviews', {
		data: {
			review: { name: `Anon review ${RUN_ID}`, rating: 10 },
			subjectType: 'module',
			subjectId: moduleUuid,
		},
		headers: { 'OCS-APIREQUEST': 'true' },
	})
	expect(
		res.status(),
		`anonymous POST /api/reviews returned ${res.status()} — expected 401/403`,
	).toBeGreaterThanOrEqual(400)
	expect(res.status()).toBeLessThan(500)
	await anon.dispose()

	// And nothing was persisted.
	const rows = await findAll(
		ctx,
		config.register,
		'software-review',
		`Anon review ${RUN_ID}`,
	)
	expect(
		rows.filter((r) => String(r.name ?? '').includes('Anon review')).length,
		'an anonymous request created a review object',
	).toBe(0)
})

// @e2e catalog-ratings::a-client-supplied-author-is-ignored
test('reviews: a client-supplied auteur/status is stripped, not stored', async () => {
	// No UI path: SubmitReviewModal never sends `auteur` or `status`, so this
	// payload cannot be produced by any sequence of clicks.
	const reviewTitle = title('rogue')
	const res = await ctx.post('/index.php/apps/stackiq/api/reviews', {
		data: {
			review: {
				name: reviewTitle,
				rating: 7,
				// The rogue fields. Neither is sent by SubmitReviewModal.
				auteur: 'Someone Else Entirely',
				status: 'approved',
			},
			subjectType: 'module',
			subjectId: moduleUuid,
		},
	})
	expect(
		res.status(),
		`POST /api/reviews returned ${res.status()}: ${await res.text()}`,
	).toBeLessThan(300)

	const rows = await findAll(ctx, config.register, 'software-review', reviewTitle)
	const stored = rows.find((r) => String(r.name ?? '') === reviewTitle)
	expect(stored, 'the review was not persisted at all').toBeTruthy()
	// The client-supplied author was IGNORED — the session identity won.
	expect(
		String(stored?.auteur ?? ''),
		'client-supplied auteur was stored',
	).not.toBe('Someone Else Entirely')
	// …and the client-supplied `approved` status was ignored too.
	expect(String(stored?.status ?? '')).toBe('pending')
})
