/**
 * SPDX-FileCopyrightText: 2026 Conduction / Stackiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Stackiq UI navigation Pinia store
 * (src/store/modules/navigation.js): the single-active-modal/dialog
 * invariant, dialog-property passing, and the consume-once transferData
 * handoff used to ferry data between views without prop drilling. Driven
 * through a real Pinia instance; console noise is silenced.
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useNavigationStore } from '../../src/store/modules/navigation.js'

describe('stackiq navigation store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'log').mockImplementation(() => {})
	})

	it('defaults to the organisaties view with no selections', () => {
		const store = useNavigationStore()
		expect(store.selected).toBe('organisaties')
		expect(store.selectedOrganisatie).toBeNull()
		expect(store.modal).toBeNull()
		expect(store.dialog).toBeNull()
		expect(store.dialogProperties).toBeNull()
		expect(store.transferData).toBeNull()
	})

	it('setSelected switches the active view', () => {
		const store = useNavigationStore()
		store.setSelected('software')
		expect(store.selected).toBe('software')
	})

	it('setSelectedOrganisatie records the active organisatie id', () => {
		const store = useNavigationStore()
		store.setSelectedOrganisatie('uuid-123')
		expect(store.selectedOrganisatie).toBe('uuid-123')
	})

	it('setModal enforces a single active modal', () => {
		const store = useNavigationStore()
		store.setModal('editOrganisatie')
		expect(store.modal).toBe('editOrganisatie')
		store.setModal(null)
		expect(store.modal).toBeNull()
	})

	it('setDialog stores the dialog name and optional properties', () => {
		const store = useNavigationStore()
		store.setDialog('deleteConfirm', { id: 'x', name: 'Foo' })
		expect(store.dialog).toBe('deleteConfirm')
		expect(store.dialogProperties).toEqual({ id: 'x', name: 'Foo' })
	})

	it('setDialog without properties defaults dialogProperties to null', () => {
		const store = useNavigationStore()
		store.setDialog('plain')
		expect(store.dialog).toBe('plain')
		expect(store.dialogProperties).toBeNull()
	})

	it('getTransferData returns the payload once then clears it', () => {
		const store = useNavigationStore()
		store.setTransferData({ foo: 'bar' })
		expect(store.transferData).toEqual({ foo: 'bar' })

		expect(store.getTransferData()).toEqual({ foo: 'bar' })
		// Consumed — a second read yields null.
		expect(store.transferData).toBeNull()
		expect(store.getTransferData()).toBeNull()
	})
})
