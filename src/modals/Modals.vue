<template>
	<div>
		<!-- Organisatie Modals -->
		<ObjectModal
			v-if="navigationStore.modal === 'organization'"
			objectTypeKey="organization" />
		<ViewObject v-if="navigationStore.modal === 'viewOrganisatie'" />
		<UploadObject v-if="navigationStore.modal === 'uploadOrganisatie'" />
		<LockObject v-if="navigationStore.modal === 'lockOrganisatie'" />
		<MigrationObject v-if="navigationStore.modal === 'migrationOrganisatie'" />
		<MergeObject v-if="navigationStore.modal === 'mergeOrganisatie'" />

		<!-- Generic Object Edit Modal for other object types (contactpersoon, etc.) -->
		<ObjectModal
			v-if="genericObjectModalType"
			:objectTypeKey="genericObjectModalType" />

		<!-- View modals for other object types -->
		<ViewObject v-if="navigationStore.modal === 'viewContactpersoon'" />
	</div>
</template>

<script>
import LockObject from './object/LockObject.vue'
import MergeObject from './object/MergeObject.vue'
import MigrationObject from './object/MigrationObject.vue'
import ObjectModal from './object/ObjectModal.vue'
import UploadObject from './object/UploadObject.vue'
import ViewObject from './object/ViewObject.vue'
import { navigationStore } from '../store/store.js'

/**
 * Object types that should use the generic ObjectModal for editing.
 * 'organization' is excluded because it has its own dedicated condition above.
 */
const GENERIC_MODAL_OBJECT_TYPES = [
	'contactPerson',
	'usage',
	'catalogContract',
	'connection',
	'module',
	'suite',
	'catalogService',
	'vulnerability',
	'software-review',
	'compliancy',
	'moduleVersion',
	'sector',
]

export default {
	name: 'Modals',
	components: {
		ObjectModal,
		ViewObject,
		UploadObject,
		LockObject,
		MigrationObject,
		MergeObject,
	},

	/**
	 * @spec exclude Pinia store wiring in setup() — bootstrap plumbing
	 */
	setup() {
		return {
			navigationStore,
		}
	},

	computed: {
		/**
		 * Returns the object type if the current modal matches a generic object type,
		 * or null if the modal is not a generic object edit modal.
		 *
		 * @return {string|null} The object type key, or null
		 * @spec exclude computed passthrough of navigation store modal type — DI getter
		 */
		genericObjectModalType() {
			const modal = navigationStore.modal
			if (!modal || typeof modal !== 'string') {
				return null
			}
			return GENERIC_MODAL_OBJECT_TYPES.includes(modal) ? modal : null
		},
	},
}
</script>
