/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/composables/orClient.js — the centralised OpenRegister
 * client surface that stamps i18n + tenant headers and builds object URLs.
 * Exact-output assertions on every branch (ADR-025 i18n + multi-tenancy).
 */

import { beforeEach, describe, expect, it } from 'vitest'
import {
	buildObjectUrl,
	buildWriteHeaders,
	OR_API_BASE,
	resolveLanguage,
	withLanguageParam,
} from '../../src/composables/orClient.js'
import { __resetLanguage, __setLanguage } from './stubs/nextcloud-l10n.js'

describe('OR_API_BASE', () => {
	it('points at the OpenRegister object API root', () => {
		expect(OR_API_BASE).toBe('/index.php/apps/openregister/api')
	})
})

describe('resolveLanguage', () => {
	beforeEach(() => {
		__resetLanguage()
	})

	it('returns the language code as-is when no region tag is present', () => {
		__setLanguage('nl')
		expect(resolveLanguage()).toBe('nl')
	})

	it('strips the region tag from BCP-47 underscore variants', () => {
		__setLanguage('en_GB')
		expect(resolveLanguage()).toBe('en')
	})

	it('strips the region tag from BCP-47 hyphen variants', () => {
		__setLanguage('pt-BR')
		expect(resolveLanguage()).toBe('pt')
	})

	it('lowercases the resolved language', () => {
		__setLanguage('FR')
		expect(resolveLanguage()).toBe('fr')
	})

	it('falls back to "en" when @nextcloud/l10n returns empty', () => {
		__setLanguage('')
		expect(resolveLanguage()).toBe('en')
	})
})

describe('withLanguageParam', () => {
	beforeEach(() => {
		__setLanguage('nl')
	})

	it('appends ?_lang to a bare URL', () => {
		expect(withLanguageParam('/api/objects')).toBe('/api/objects?_lang=nl')
	})

	it('appends &_lang when the URL already has a query string', () => {
		expect(withLanguageParam('/api/objects?_limit=5')).toBe(
			'/api/objects?_limit=5&_lang=nl',
		)
	})

	it('does not overwrite an explicit _lang already on the URL', () => {
		expect(withLanguageParam('/api/objects?_lang=en')).toBe(
			'/api/objects?_lang=en',
		)
	})

	it('accepts an explicit lang override', () => {
		expect(withLanguageParam('/api/objects', 'de')).toBe('/api/objects?_lang=de')
	})

	it('returns the URL untouched when empty', () => {
		expect(withLanguageParam('')).toBe('')
	})

	it('URL-encodes unusual language codes', () => {
		expect(withLanguageParam('/x', 'zh hans')).toBe('/x?_lang=zh%20hans')
	})
})

describe('buildWriteHeaders', () => {
	it('returns base headers unchanged when no options are passed', () => {
		const base = { 'Content-Type': 'application/json' }
		const headers = buildWriteHeaders(base)
		expect(headers).toEqual({ 'Content-Type': 'application/json' })
		expect(headers).not.toBe(base) // new object, not mutated
	})

	it('stamps X-Translation-Target-Language when targetLang is supplied', () => {
		const headers = buildWriteHeaders({ X: 'y' }, { targetLang: 'fr' })
		expect(headers['X-Translation-Target-Language']).toBe('fr')
		expect(headers.X).toBe('y')
	})

	it('omits X-Translation-Target-Language when targetLang is null', () => {
		const headers = buildWriteHeaders({}, { targetLang: null })
		expect(headers['X-Translation-Target-Language']).toBeUndefined()
	})

	it('omits X-Translation-Target-Language when targetLang is empty string', () => {
		const headers = buildWriteHeaders({}, { targetLang: '' })
		expect(headers['X-Translation-Target-Language']).toBeUndefined()
	})

	it('stamps X-OpenRegister-Organisation when organisation is supplied', () => {
		const headers = buildWriteHeaders({}, { organisation: 'org-uuid-1' })
		expect(headers['X-OpenRegister-Organisation']).toBe('org-uuid-1')
	})

	it('omits X-OpenRegister-Organisation when organisation is null', () => {
		const headers = buildWriteHeaders({}, { organisation: null })
		expect(headers['X-OpenRegister-Organisation']).toBeUndefined()
	})

	it('stamps both headers when both supplied', () => {
		const headers = buildWriteHeaders(
			{},
			{ targetLang: 'nl', organisation: 'uuid-2' },
		)
		expect(headers['X-Translation-Target-Language']).toBe('nl')
		expect(headers['X-OpenRegister-Organisation']).toBe('uuid-2')
	})

	it('handles undefined baseHeaders argument', () => {
		const headers = buildWriteHeaders()
		expect(headers).toEqual({})
	})
})

describe('buildObjectUrl', () => {
	beforeEach(() => {
		__setLanguage('en')
	})

	it('builds a collection URL with register + schema (no uuid)', () => {
		const url = buildObjectUrl({ register: 1, schema: 2 })
		expect(url).toBe('/index.php/apps/openregister/api/objects/1/2?_lang=en')
	})

	it('builds a single-object URL when uuid is present', () => {
		const url = buildObjectUrl({
			register: 'reg-a',
			schema: 'sch-b',
			uuid: 'abc-123',
		})
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/reg-a/sch-b/abc-123?_lang=en',
		)
	})

	it('maps the "logs" action to /audit-trails', () => {
		const url = buildObjectUrl({
			register: 1,
			schema: 2,
			uuid: 'x',
			action: 'logs',
		})
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/1/2/x/audit-trails?_lang=en',
		)
	})

	it('appends a non-logs action verbatim', () => {
		const url = buildObjectUrl({
			register: 1,
			schema: 2,
			uuid: 'x',
			action: 'publish',
		})
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/1/2/x/publish?_lang=en',
		)
	})

	it('omits _lang when withLang is false', () => {
		const url = buildObjectUrl({ register: 1, schema: 2, withLang: false })
		expect(url).toBe('/index.php/apps/openregister/api/objects/1/2')
	})

	it('throws when register is missing', () => {
		expect(() => buildObjectUrl({ schema: 2 })).toThrow(
			/register and schema are required/,
		)
	})

	it('throws when schema is missing', () => {
		expect(() => buildObjectUrl({ register: 1 })).toThrow(
			/register and schema are required/,
		)
	})

	it('throws when register is null', () => {
		expect(() => buildObjectUrl({ register: null, schema: 1 })).toThrow(
			/register and schema are required/,
		)
	})
})
