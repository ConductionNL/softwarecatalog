// @vitest-environment jsdom

/**
 * Unit tests for the admin API helpers (federation + moderation REST contract).
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import {
	apiRequest,
	apiUrl,
	normaliseFederationStatus,
} from '../../src/utils/adminApi.js'

describe('adminApi.apiUrl', () => {
	it('builds the app API URL, tolerating leading slashes', () => {
		expect(apiUrl('federation/status')).toBe(
			'/index.php/apps/stackiq/api/federation/status',
		)
		expect(apiUrl('/federation/status')).toBe(
			'/index.php/apps/stackiq/api/federation/status',
		)
	})
})

describe('adminApi.apiRequest', () => {
	it('returns the parsed body on a 2xx response', async () => {
		const fetchImpl = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			json: async () => ({ ok: true, items: [] }),
		})
		const data = await apiRequest('moderation/pending', {}, fetchImpl)
		expect(data).toEqual({ ok: true, items: [] })
		expect(fetchImpl).toHaveBeenCalledWith(
			'/index.php/apps/stackiq/api/moderation/pending',
			expect.objectContaining({ method: 'GET' }),
		)
	})

	it('serialises a JSON body and sets the method', async () => {
		const fetchImpl = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			json: async () => ({ ok: true }),
		})
		await apiRequest(
			'federation/peers',
			{ method: 'POST', body: { peerUrl: 'https://x.org' } },
			fetchImpl,
		)
		const init = fetchImpl.mock.calls[0][1]
		expect(init.method).toBe('POST')
		expect(JSON.parse(init.body)).toEqual({ peerUrl: 'https://x.org' })
		expect(init.headers['Content-Type']).toBe('application/json')
	})

	it('throws the server message on a non-2xx response', async () => {
		const fetchImpl = vi.fn().mockResolvedValue({
			ok: false,
			status: 400,
			json: async () => ({ message: 'peer host blocked by SSRF guard' }),
		})
		await expect(
			apiRequest('federation/peers', { method: 'POST' }, fetchImpl),
		).rejects.toThrow('peer host blocked by SSRF guard')
	})

	it('falls back to an HTTP-status error when the body carries no message', async () => {
		const fetchImpl = vi.fn().mockResolvedValue({
			ok: false,
			status: 500,
			json: async () => {
				throw new Error('not json')
			},
		})
		await expect(apiRequest('federation/status', {}, fetchImpl)).rejects.toThrow(
			'HTTP 500',
		)
	})
})

describe('adminApi.normaliseFederationStatus', () => {
	it('normalises the enriched per-peer shape', () => {
		const out = normaliseFederationStatus({
			available: true,
			enabled: true,
			directoryUrl: 'https://directory.opencatalogi.nl',
			staleAfter: 3,
			message: 'Federation available',
			peers: [
				{ url: 'https://a.org', failures: 0, stale: false, allowed: true },
				{ url: 'https://b.org', failures: 4, stale: true, allowed: true },
			],
		})
		expect(out.available).toBe(true)
		expect(out.peers).toHaveLength(2)
		expect(out.peers[1]).toEqual({
			url: 'https://b.org',
			failures: 4,
			stale: true,
			allowed: true,
		})
	})

	it('tolerates a legacy string[] peers payload', () => {
		const out = normaliseFederationStatus({ peers: ['https://legacy.org'] })
		expect(out.peers[0]).toEqual({
			url: 'https://legacy.org',
			failures: 0,
			stale: false,
			allowed: true,
		})
		expect(out.available).toBe(false)
		expect(out.staleAfter).toBe(3)
	})

	it('returns safe defaults for an empty/garbage payload', () => {
		const out = normaliseFederationStatus(undefined)
		expect(out.peers).toEqual([])
		expect(out.enabled).toBe(false)
		expect(out.directoryUrl).toBe('')
	})
})
