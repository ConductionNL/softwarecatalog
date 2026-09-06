<template>
	<CnAdminSettingsShell
		appId="stackiq"
		appName="Stackiq"
		:appVersion="versionInfo.appVersion || appVersion"
		:configuredVersion="versionInfo.configuredVersion || ''"
		:isUpToDate="versionInfo.versionsMatch !== false"
		:showReimport="false">
		<!-- Version-card maintenance actions (moved from the old VersionInformation section) -->
		<template #actions>
			<NcButton
				v-if="versionInfo.autoConfigCompleted === false"
				variant="secondary"
				:disabled="autoConfiguring"
				@click="consolidatedAutoConfigure">
				Auto Configure
			</NcButton>
			<NcButton
				class="ml-8"
				variant="error"
				:disabled="autoConfiguring"
				@click="handleForceUpdate">
				Force Update
			</NcButton>
			<NcButton
				v-if="versionInfo.autoConfigCompleted === true"
				class="ml-8"
				variant="tertiary"
				:disabled="autoConfiguring"
				@click="handleResetAutoConfig">
				Reset Auto-Config
			</NcButton>
		</template>

		<!-- Statistics Overview Section -->
		<StatisticsOverview />

		<!-- General Settings Section -->
		<AlwaysVisibleSection
			name="General Settings"
			description="Configure basic application settings"
			:loading="store.loadingGeneralSettings"
			loadingText="Loading general settings..."
			:showSaveButton="true"
			:showRefreshButton="true"
			:canSave="catalogLocationChanged"
			:saving="savingCatalogLocation"
			saveButtonText="Save General Settings"
			@save="saveGeneralSettings"
			@refresh="refreshGeneralSettings">
			<!-- Stackiq Location -->
			<div class="catalog-location-section">
				<h3>Stackiq Location</h3>
				<p>Set the base URL for your software catalog interface</p>

				<NcTextField
					v-model="catalogLocation"
					:label="t('stackiq', 'Stackiq Location URL')"
					:placeholder="t('stackiq', 'https://catalog.example.com')"
					:disabled="store.loading">
					<template #icon>
						<Web :size="16" />
					</template>
				</NcTextField>

				<div class="catalog-location-help">
					<p class="help-text">
						This URL will be used for external links to your software
						catalog. The system will append "/beheer" to this URL for
						management interfaces.
					</p>
				</div>
			</div>
		</AlwaysVisibleSection>

		<!-- OpenRegister Integration Section -->
		<OpenRegisterIntegration />

		<!-- User Groups Configuration Section -->
		<UserGroupsConfiguration />

		<!-- Organization Synchronization Section -->
		<OrganizationSynchronization />

		<!-- ArchiMate Import/Export Section -->
		<ArchiMateImportExport />

		<!-- Email Configuration Section -->
		<EmailConfiguration />

		<!-- Registration Moderation Queue Section -->
		<ModerationQueue />

		<!-- Review Moderation Queue Section (stackiq#375) — the
		     same ModerationQueue.vue component, parameterised for the
		     beoordeeling type, per catalog-ratings spec's "reuse the
		     pattern, don't invent a second mechanism" requirement. -->
		<ModerationQueue
			type="software-review"
			entityLabel="review"
			:name="t('stackiq', 'Review moderation')"
			:description="
				t(
					'stackiq',
					'Review pending ratings and testimonials. Approving a review publishes it; rejecting leaves it hidden.',
				)
			"
			:loadingText="t('stackiq', 'Loading pending reviews…')"
			:emptyDescription="
				t('stackiq', 'There are no pending reviews right now.')
			" />

		<!-- Catalog Federation Section -->
		<FederationSettings />

		<!-- End-of-life Feed Sync Section -->
		<EolSyncSettings />

		<!-- Background Jobs Configuration Section -->
		<CronjobConfiguration />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { defineComponent } from 'vue'
import Web from 'vue-material-design-icons/Web.vue'
import AlwaysVisibleSection from '../../components/AlwaysVisibleSection.vue'
import ArchiMateImportExport from './sections/ArchiMateImportExport.vue'
import CronjobConfiguration from './sections/CronjobConfiguration.vue'
import EmailConfiguration from './sections/EmailConfiguration.vue'
import EolSyncSettings from './sections/EolSyncSettings.vue'
import FederationSettings from './sections/FederationSettings.vue'
import ModerationQueue from './sections/ModerationQueue.vue'
import OpenRegisterIntegration from './sections/OpenRegisterIntegration.vue'
import OrganizationSynchronization from './sections/OrganizationSynchronization.vue'
import StatisticsOverview from './sections/StatisticsOverview.vue'
import UserGroupsConfiguration from './sections/UserGroupsConfiguration.vue'
import { settingsStore } from '../../store/store.js'

/**
 * Stackiq Settings component
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2
 * @version  1.0.0
 */
export default defineComponent({
	name: 'StackiqSettings',
	components: {
		CnAdminSettingsShell,
		NcButton,
		NcTextField,
		OpenRegisterIntegration,
		StatisticsOverview,
		UserGroupsConfiguration,
		OrganizationSynchronization,
		ArchiMateImportExport,
		EmailConfiguration,
		CronjobConfiguration,
		ModerationQueue,
		FederationSettings,
		EolSyncSettings,
		AlwaysVisibleSection,
		Web,
	},

	/**
	 * @spec openspec/specs/fe-settings-ui/spec.md
	 */
	setup() {
		// Use the settings store
		return {
			store: settingsStore,
		}
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			appVersion: loadState('stackiq', 'version', 'Unknown'),
			savingCatalogLocation: false,
			catalogLocation: '',
			autoConfiguring: false,
		}
	},

	computed: {
		/**
		 * Version info from the settings store, used to drive the shell's version card
		 * (configured version + up-to-date badge) and the maintenance action buttons.
		 *
		 * @return {object} Version information
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		versionInfo() {
			return this.store.versionInfo || {}
		},

		/**
		 * Check if catalog location has changed
		 *
		 * @return {boolean} True if catalog location has changed
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		catalogLocationChanged() {
			return (
				this.catalogLocation !== (this.store.settings.catalogLocation || '')
			)
		},
	},

	/**
	 * Watch for changes in the store's catalogLocation
	 */
	watch: {
		'store.settings.catalogLocation': {
			/**
			 * @param newValue
			 * @spec openspec/specs/fe-settings-ui/spec.md
			 */
			handler(newValue) {
				if (newValue !== undefined && newValue !== null) {
					this.catalogLocation = newValue
				}
			},

			immediate: true,
		},

		'store.loadingGeneralSettings': {
			/**
			 * @param newValue
			 * @param oldValue
			 * @spec openspec/specs/fe-settings-ui/spec.md
			 */
			handler(newValue, oldValue) {
				// When loading finishes, update the catalog location
				if (oldValue === true && newValue === false) {
					this.catalogLocation = this.store.settings.catalogLocation || ''
				}
			},
		},
	},

	/**
	 * Load settings data when component is created
	 *
	 * @spec openspec/specs/fe-settings-ui/spec.md
	 */
	async created() {
		await this.store.loadSettings()
		// Initialize catalog location from store
		this.catalogLocation = this.store.settings.catalogLocation || ''
	},

	methods: {
		t,

		/**
		 * Handle catalog location input change
		 *
		 * @param {string} value - New catalog location value
		 * @return {void}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		onCatalogLocationChange(value) {
			this.catalogLocation = value
		},

		/**
		 * Save general settings using the centralized settings store
		 *
		 * @async
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async saveGeneralSettings() {
			this.savingCatalogLocation = true
			try {
				// First update the store with the new catalog location value
				await this.store.updateCatalogLocation(this.catalogLocation)
				// Then use the settings store's centralized save method
				await this.store.saveConfiguration()
				console.info('General settings saved successfully')
			} catch (error) {
				console.error('Failed to save general settings:', error)
			} finally {
				this.savingCatalogLocation = false
			}
		},

		/**
		 * Refresh general settings from the store
		 *
		 * @async
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async refreshGeneralSettings() {
			try {
				await this.store.loadGeneralConfig()
				// Reset the local catalog location to match the store
				this.catalogLocation = this.store.settings.catalogLocation || ''
			} catch (error) {
				console.error('Failed to refresh general settings:', error)
			}
		},

		/**
		 * Perform consolidated auto-configuration using the settings store.
		 * Feedback is surfaced via toast notifications.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async consolidatedAutoConfigure() {
			this.autoConfiguring = true
			try {
				const result = await this.store.consolidatedAutoConfigure()
				if (result && result.success) {
					showSuccess('Auto configuration completed successfully')
				} else if (result && result.message) {
					showError('Auto configuration failed: ' + result.message)
				}
				await this.store.loadVersionInfo()
			} catch (error) {
				console.error('Failed to perform auto-configuration:', error)
				showError('Failed to perform auto-configuration: ' + error.message)
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * Force a full re-import and version sync via the settings store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async handleForceUpdate() {
			this.autoConfiguring = true
			try {
				const result = await this.store.forceUpdate()
				await this.store.loadVersionInfo()
				if (result && result.success) {
					showSuccess(
						result.message || 'Force update completed successfully',
					)
				} else if (result) {
					showError(result.message || 'Force update failed')
				}
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * Reset the auto-config completed flag via the settings store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async handleResetAutoConfig() {
			this.autoConfiguring = true
			try {
				const result = await this.store.resetAutoConfig()
				await this.store.loadVersionInfo()
				if (result && result.success) {
					showSuccess(result.message || 'Auto-config reset successfully')
				} else if (result) {
					showError(result.message || 'Failed to reset auto-config')
				}
			} finally {
				this.autoConfiguring = false
			}
		},
	},
})
</script>

<style scoped>
.catalog-location-section {
	margin-bottom: 2rem;
	max-width: 500px;
}

.catalog-location-help {
	margin-top: 0.5rem;
	margin-bottom: 1rem;
}

.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}

.loading-text {
	text-align: center;
	color: var(--color-text-lighter);
	margin-top: 1rem;
	font-size: 14px;
}

.ml-8 {
	margin-left: 8px;
}
</style>
