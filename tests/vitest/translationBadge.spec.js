/**
 * SPDX-FileCopyrightText: 2026 Conduction / Stackiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/utils/translationBadge.js — the pure, framework-light
 * helper that decides whether an index row shows a "(translated from X)"
 * badge based on the OpenRegister `sourceLanguage` metadata vs. the served
 * language. Exact-output assertions on every branch.
 */

import { describe, expect, it } from 'vitest'
import {
	getSourceLanguage,
	languageName,
	shouldShowTranslationBadge,
	translationBadge,
} from '../../src/utils/translationBadge.js'

describe('languageName', () => {
	it('maps known codes to English display names', () => {
		expect(languageName('nl')).toBe('Dutch')
		expect(languageName('en')).toBe('English')
		expect(languageName('de')).toBe('German')
		expect(languageName('fr')).toBe('French')
	})

	it('normalises locale variants before lookup', () => {
		expect(languageName('en_GB')).toBe('English')
		expect(languageName('NL-nl')).toBe('Dutch')
	})

	it('falls back to the upper-cased code for unknown languages', () => {
		expect(languageName('es')).toBe('ES')
		expect(languageName('pt-BR')).toBe('PT')
	})

	it('returns empty string for empty/invalid input', () => {
		expect(languageName('')).toBe('')
		expect(languageName(null)).toBe('')
		expect(languageName(undefined)).toBe('')
	})
})

describe('getSourceLanguage', () => {
	it('reads a top-level sourceLanguage', () => {
		expect(getSourceLanguage({ sourceLanguage: 'NL' })).toBe('nl')
	})

	it('reads sourceLanguage from the @self envelope (camelCase + snake_case)', () => {
		expect(getSourceLanguage({ '@self': { sourceLanguage: 'en_US' } })).toBe(
			'en',
		)
		expect(getSourceLanguage({ '@self': { source_language: 'DE' } })).toBe('de')
	})

	it('returns empty string when absent or not an object', () => {
		expect(getSourceLanguage({})).toBe('')
		expect(getSourceLanguage(null)).toBe('')
		expect(getSourceLanguage('nope')).toBe('')
	})
})

describe('shouldShowTranslationBadge', () => {
	it('is true only when source differs from served and both are known', () => {
		expect(shouldShowTranslationBadge({ sourceLanguage: 'nl' }, 'en')).toBe(true)
	})

	it('is false when source equals served (case/locale-insensitive)', () => {
		expect(shouldShowTranslationBadge({ sourceLanguage: 'EN' }, 'en_GB')).toBe(
			false,
		)
	})

	it('is false when the source language is unknown (never guess)', () => {
		expect(shouldShowTranslationBadge({}, 'en')).toBe(false)
	})

	it('is false when the served language is unknown', () => {
		expect(shouldShowTranslationBadge({ sourceLanguage: 'nl' }, '')).toBe(false)
	})
})

describe('translationBadge', () => {
	it('returns null when no badge is warranted', () => {
		expect(translationBadge({ sourceLanguage: 'en' }, 'en')).toBeNull()
		expect(translationBadge({}, 'en')).toBeNull()
	})

	it('returns the descriptor with source code + display name', () => {
		const badge = translationBadge({ sourceLanguage: 'nl' }, 'en')
		expect(badge).not.toBeNull()
		expect(badge.sourceLanguage).toBe('nl')
		expect(badge.sourceLanguageName).toBe('Dutch')
	})

	it('label() uses the provided t() with interpolation', () => {
		const badge = translationBadge({ sourceLanguage: 'nl' }, 'en')
		const t = (app, str, vars) => `${app}:${str}:${vars.language}`
		expect(badge.label(t)).toBe('stackiq:(translated from {language}):Dutch')
	})

	it('label() falls back to a plain English string without t()', () => {
		const badge = translationBadge({ sourceLanguage: 'fr' }, 'en')
		expect(badge.label()).toBe('(translated from French)')
		expect(badge.label('not-a-function')).toBe('(translated from French)')
	})
})
