/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/utils/heartbeat.js — the long-operation keep-alive
 * helper that prevents 504 gateway timeouts on long-running sync /
 * import jobs by periodically POSTing /api/heartbeat. The class itself
 * is pure JS (timers + fetch); these tests pin its lifecycle, idempotency
 * and the withHeartbeat convenience wrapper.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The module instantiates a singleton + reads global OC.requestToken inside
// sendHeartbeat(). We stub the globals and the fetch implementation before
// importing the module under test.
beforeEach(() => {
	global.OC = { requestToken: 'test-token' }
	global.fetch = vi.fn(() =>
		Promise.resolve({ ok: true, status: 200, statusText: 'OK' }),
	)
	vi.useFakeTimers()
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	delete global.OC
	delete global.fetch
})

async function loadModule() {
	// Bust the singleton between tests so each test starts with a fresh
	// stopped instance.
	vi.resetModules()
	return await import('../../src/utils/heartbeat.js')
}

describe('Heartbeat singleton', () => {
	it('exposes start/stop/running helpers', async () => {
		const mod = await loadModule()
		expect(typeof mod.startHeartbeat).toBe('function')
		expect(typeof mod.stopHeartbeat).toBe('function')
		expect(typeof mod.isHeartbeatRunning).toBe('function')
		expect(typeof mod.withHeartbeat).toBe('function')
		expect(mod.default).toBeDefined()
	})

	it('starts in stopped state', async () => {
		const { isHeartbeatRunning } = await loadModule()
		expect(isHeartbeatRunning()).toBe(false)
	})

	it('starts and reports running=true', async () => {
		const { startHeartbeat, isHeartbeatRunning, stopHeartbeat } =
			await loadModule()
		startHeartbeat()
		expect(isHeartbeatRunning()).toBe(true)
		stopHeartbeat()
	})

	it('fires an immediate heartbeat on start', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		startHeartbeat()
		// First heartbeat is fired synchronously inside start()
		expect(global.fetch).toHaveBeenCalledTimes(1)
		const [url, opts] = global.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/stackiq/api/heartbeat')
		expect(opts.method).toBe('POST')
		expect(opts.headers['Content-Type']).toBe('application/json')
		expect(opts.headers.requesttoken).toBe('test-token')
		const body = JSON.parse(opts.body)
		expect(typeof body.timestamp).toBe('number')
		stopHeartbeat()
	})

	it('fires repeated heartbeats on each interval tick', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		startHeartbeat(1000)
		// Immediate
		expect(global.fetch).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(1000)
		expect(global.fetch).toHaveBeenCalledTimes(2)
		vi.advanceTimersByTime(3000)
		expect(global.fetch).toHaveBeenCalledTimes(5)
		stopHeartbeat()
	})

	it('stop() halts further heartbeats', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		startHeartbeat(500)
		vi.advanceTimersByTime(500)
		expect(global.fetch).toHaveBeenCalledTimes(2)
		stopHeartbeat()
		vi.advanceTimersByTime(5000)
		// No further calls after stop
		expect(global.fetch).toHaveBeenCalledTimes(2)
	})

	it('start() is idempotent — calling twice does not double-schedule', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		startHeartbeat(1000)
		startHeartbeat(1000)
		// Each start() only fires its immediate beat once because the
		// second call short-circuits on isRunning=true.
		expect(global.fetch).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(1000)
		// Only ONE additional timer fired
		expect(global.fetch).toHaveBeenCalledTimes(2)
		stopHeartbeat()
	})

	it('stop() is idempotent — calling on a stopped instance is a no-op', async () => {
		const { stopHeartbeat, isHeartbeatRunning } = await loadModule()
		expect(() => stopHeartbeat()).not.toThrow()
		expect(isHeartbeatRunning()).toBe(false)
		stopHeartbeat()
		expect(isHeartbeatRunning()).toBe(false)
	})

	it('swallows fetch network errors so the timer never dies', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		global.fetch = vi.fn(() => Promise.reject(new Error('boom')))
		startHeartbeat(1000)
		// Let the immediate beat reject
		await Promise.resolve()
		await Promise.resolve()
		vi.advanceTimersByTime(1000)
		await Promise.resolve()
		// Two attempts — failure did not stop the timer
		expect(global.fetch).toHaveBeenCalledTimes(2)
		stopHeartbeat()
	})

	it('tolerates non-2xx responses without throwing', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		global.fetch = vi.fn(() =>
			Promise.resolve({
				ok: false,
				status: 504,
				statusText: 'Gateway Timeout',
			}),
		)
		startHeartbeat(1000)
		await Promise.resolve()
		await Promise.resolve()
		expect(global.fetch).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(1000)
		await Promise.resolve()
		expect(global.fetch).toHaveBeenCalledTimes(2)
		stopHeartbeat()
	})

	it('startHeartbeat(interval) overrides the default 30s', async () => {
		const { startHeartbeat, stopHeartbeat } = await loadModule()
		startHeartbeat(250)
		vi.advanceTimersByTime(250)
		expect(global.fetch).toHaveBeenCalledTimes(2)
		vi.advanceTimersByTime(250)
		expect(global.fetch).toHaveBeenCalledTimes(3)
		stopHeartbeat()
	})
})

describe('withHeartbeat()', () => {
	it('runs the operation, returns its result, and stops the heartbeat', async () => {
		const { withHeartbeat, isHeartbeatRunning } = await loadModule()
		const op = vi.fn(() => Promise.resolve('done'))
		const result = await withHeartbeat(op, 1000)
		expect(result).toBe('done')
		expect(op).toHaveBeenCalledTimes(1)
		// The immediate beat fires at start
		expect(global.fetch).toHaveBeenCalledTimes(1)
		expect(isHeartbeatRunning()).toBe(false)
	})

	it('stops the heartbeat even if the operation rejects', async () => {
		const { withHeartbeat, isHeartbeatRunning } = await loadModule()
		const op = vi.fn(() => Promise.reject(new Error('op-failed')))
		await expect(withHeartbeat(op, 1000)).rejects.toThrow('op-failed')
		expect(isHeartbeatRunning()).toBe(false)
	})

	it('uses the default 30s interval when none is provided', async () => {
		const { withHeartbeat, isHeartbeatRunning } = await loadModule()
		const op = vi.fn(() => Promise.resolve('ok'))
		await withHeartbeat(op)
		// Immediate beat fired; we never advanced 30s so no further calls
		expect(global.fetch).toHaveBeenCalledTimes(1)
		expect(isHeartbeatRunning()).toBe(false)
	})
})
