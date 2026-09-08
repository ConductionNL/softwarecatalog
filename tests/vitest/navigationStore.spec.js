/**
 * Unit tests for the navigation Pinia store.
 *
 * MOVED FROM `src/store/modules/navigation.spec.js` (jest) TO vitest.
 * pinia 4 is ESM-only — `type: module`, an `exports` map naming
 * `dist/pinia.js`, and no CommonJS build at all — so jest's CJS runtime
 * cannot require it and this suite died in `createRequireEsmError` before a
 * single assertion ran. vitest loads ESM natively, which is why the repo
 * already runs it alongside jest.
 *
 * Nothing about the assertions changed: this file used no jest-specific API,
 * only `describe`/`it`/`expect`, which vitest provides under the same names.
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useNavigationStore } from '../../src/store/modules/navigation.js'

describe('Navigation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('set current selected view correctly', () => {
		const store = useNavigationStore()

		store.setSelected('organisaties')
		expect(store.selected).toBe('organisaties')

		store.setSelected('software')
		expect(store.selected).toBe('software')

		store.setSelected('licenses')
		expect(store.selected).toBe('licenses')
	})

	it('set current selected organisatie correctly', () => {
		const store = useNavigationStore()

		store.setSelectedOrganisatie('7a048bfd-210f-4e93-a1e8-5aa9261740b7')
		expect(store.selectedOrganisatie).toBe(
			'7a048bfd-210f-4e93-a1e8-5aa9261740b7',
		)

		store.setSelectedOrganisatie('dd133c51-89bc-4b06-bdbb-41f4dc07c4f1')
		expect(store.selectedOrganisatie).toBe(
			'dd133c51-89bc-4b06-bdbb-41f4dc07c4f1',
		)

		store.setSelectedOrganisatie('3b1cbee2-756e-4904-a157-29fb0cbe01d3')
		expect(store.selectedOrganisatie).toBe(
			'3b1cbee2-756e-4904-a157-29fb0cbe01d3',
		)
	})

	it('set modal correctly', () => {
		const store = useNavigationStore()

		store.setModal('editOrganisatie')
		expect(store.modal).toBe('editOrganisatie')

		store.setModal('editSoftware')
		expect(store.modal).toBe('editSoftware')

		store.setModal('editLicense')
		expect(store.modal).toBe('editLicense')
	})

	it('set dialog correctly', () => {
		const store = useNavigationStore()

		store.setDialog('deleteOrganisatie')
		expect(store.dialog).toBe('deleteOrganisatie')

		store.setDialog('deleteSoftware')
		expect(store.dialog).toBe('deleteSoftware')

		store.setDialog('deleteLicense')
		expect(store.dialog).toBe('deleteLicense')
	})
})
