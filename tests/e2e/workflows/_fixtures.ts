// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Seeded-fixture helpers for the DEEP, data-dependent stackiq e2e
 * workflows.
 *
 * These create and clean up real catalog objects (Organisatie, Component
 * (=`module` schema), Contactpersoon) through the OpenRegister object API so
 * the UI workflows below run against KNOWN, deterministic data.
 *
 * Design rules (per the gate-19 honest-coverage program):
 *  - Fixture SETUP / TEARDOWN may use the API; only ASSERTIONS must be driven
 *    through the rendered UI.
 *  - Every seeded object carries a unique `e2e-<runId>` token in its name so a
 *    test can locate exactly its own row in a shared dev dataset, and the
 *    afterAll cleanup deletes only what this run created (token match) — it
 *    never touches the pre-existing demo data.
 *  - The register id + schema slugs are RESOLVED at runtime from the app's own
 *    `voorzieningen/config` endpoint (register 11, organisatie schema 39,
 *    contactpersoon 38, module 50, ...). We do NOT hard-code numeric schema
 *    ids — if the env re-seeds, the resolved ids follow.
 *  - All OR verbs used are the REAL ones (findAll = GET collection, createObject
 *    = POST, deleteObject = DELETE on `/api/objects/{register}/{schema}[/{id}]`).
 */

import type { APIRequestContext } from '@playwright/test'

import { request as playwrightRequest } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

// Re-exported from the single central resolver (tests/e2e/base-url.ts). These
// fixtures CREATE organisations and contracts, so a `localhost:8080` fallback
// here would write test data into the shared dev container.
export const BASE_URL = resolveBaseUrl()
export const NC_ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
export const NC_ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

/** Unique per Node process so parallel-ish runs never collide. */
export const RUN_ID = `e2e-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

export interface VoorzieningenConfig {
	register: string
	organisatie_schema: string
	contactpersoon_schema: string
	module_schema: string
	contract_schema: string
	[key: string]: string
}

/**
 * Build a basic-auth API context. Basic auth bypasses the CSRF requesttoken
 * that cookie writes require, so the seed/cleanup POST/DELETE calls succeed.
 *
 * `send: 'always'` is REQUIRED: Nextcloud answers an unauthenticated API
 * request with `401` but WITHOUT a `WWW-Authenticate: Basic` challenge header,
 * so Playwright's default reactive `httpCredentials` (send: 'unauthorized')
 * never retries with the Authorization header and every seed/config call fails
 * with 401. Sending the header pre-emptively on the first request fixes it.
 */
export async function newApiContext(): Promise<APIRequestContext> {
	return await playwrightRequest.newContext({
		baseURL: BASE_URL,
		httpCredentials: {
			username: NC_ADMIN_USER,
			password: NC_ADMIN_PASS,
			send: 'always',
		},
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
}

/** Resolve the voorzieningen register + schema slugs from the app config endpoint. */
export async function resolveConfig(
	ctx: APIRequestContext,
): Promise<VoorzieningenConfig> {
	const res = await ctx.get('/index.php/apps/stackiq/api/voorzieningen/config')
	if (!res.ok()) {
		throw new Error(`voorzieningen/config returned ${res.status()}`)
	}
	const body = await res.json()
	const config = body?.config
	if (!config?.register) {
		throw new Error('voorzieningen register not configured')
	}
	return config as VoorzieningenConfig
}

/**
 * Create one object in a register/schema via the OR createObject verb (POST).
 * Returns the created object's id.
 */
export async function createObject(
	ctx: APIRequestContext,
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await ctx.post(
		`/index.php/apps/openregister/api/objects/${register}/${schema}`,
		{ data },
	)
	if (!res.ok()) {
		throw new Error(
			`createObject(${register}/${schema}) ${res.status()}: ${await res.text()}`,
		)
	}
	const body = await res.json()
	const id = body?.id ?? body?.['@self']?.id
	if (!id) {
		throw new Error(
			`createObject returned no id: ${JSON.stringify(body).slice(0, 200)}`,
		)
	}
	return String(id)
}

/** Delete one object via the OR deleteObject verb (DELETE). Swallows 404. */
export async function deleteObject(
	ctx: APIRequestContext,
	register: string,
	schema: string,
	id: string,
): Promise<void> {
	const res = await ctx.delete(
		`/index.php/apps/openregister/api/objects/${register}/${schema}/${id}`,
	)
	if (!res.ok() && res.status() !== 404) {
		// Non-fatal during cleanup; log only.

		console.warn(
			`deleteObject(${register}/${schema}/${id}) returned ${res.status()}`,
		)
	}
}

/**
 * findAll: fetch a collection (optionally filtered by a free-text search) and
 * return the result rows. Used by cleanup to find this run's seeded rows and by
 * read-persistence assertions.
 */
export async function findAll(
	ctx: APIRequestContext,
	register: string,
	schema: string,
	search?: string,
): Promise<Array<Record<string, unknown>>> {
	const q = search
		? `?_search=${encodeURIComponent(search)}&_limit=200`
		: '?_limit=5000'
	const res = await ctx.get(
		`/index.php/apps/openregister/api/objects/${register}/${schema}${q}`,
	)
	if (!res.ok()) return []
	const body = await res.json()
	const list = body?.results ?? body ?? []
	return Array.isArray(list) ? list : []
}

/** Pull a printable name off a catalog object (schemas use `naam`). */
export function nameOf(o: Record<string, unknown>): string {
	return String(
		o.name
			?? o.name
			?? (o as { '@self'?: { name?: string } })['@self']?.name
			?? '',
	)
}

/**
 * Delete every object across the catalog schemas whose name contains the given
 * token. Called from afterAll so a run never leaves residue and never touches
 * the demo data. Iterates the schemas a workflow may seed into.
 */
export async function cleanupByToken(
	ctx: APIRequestContext,
	config: VoorzieningenConfig,
	token: string,
): Promise<number> {
	const schemas = [
		config.organisatie_schema,
		config.contactpersoon_schema,
		config.module_schema,
		config.contract_schema,
		config.moduleVersie_schema,
	].filter(Boolean)
	let removed = 0
	for (const schema of schemas) {
		const rows = await findAll(ctx, config.register, schema)
		for (const row of rows) {
			if (nameOf(row).includes(token) || JSON.stringify(row).includes(token)) {
				const id = String(
					(row as { id?: string }).id
						?? (row as { '@self'?: { id?: string } })['@self']?.id
						?? '',
				)
				if (id) {
					await deleteObject(ctx, config.register, schema, id)
					removed++
				}
			}
		}
	}
	return removed
}
