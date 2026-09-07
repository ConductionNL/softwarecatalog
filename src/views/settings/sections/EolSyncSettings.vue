<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - EOL feed sync admin section: configure the register/schema names the
 - matcher reads `eol_product`/`eol_cycle` from (provisioned by the sibling
 - Integriq `endoflife-date-source` change), enable/disable the feature,
 - and trigger a manual "sync now" run. Rendered inside the admin settings
 - panel — admin-gated by the IDelegatedSettings framework and by the default
 - admin-required posture of every SettingsController method (no
 - `@NoAdminRequired`); NOT registered in the in-app router. Shows
 - "unavailable" as a status, never an error, when the feed cannot be
 - resolved (Integriq not installed, disabled, or misconfigured) — per
 - the graceful-degradation requirement, manual `datumEindeOndersteuning`
 - entry keeps working regardless of what this panel shows.
 -->

<template>
	<AlwaysVisibleSection
		:name="t('stackiq', 'End-of-life feed sync')"
		:description="
			t(
				'stackiq',
				'Match catalog products to endoflife.date product cycles ingested via OpenConnector, to keep end-of-support dates data-driven. Stackiq never calls endoflife.date directly.',
			)
		"
		:loading="loading"
		:loadingText="t('stackiq', 'Loading EOL sync configuration…')"
		:showSaveButton="true"
		:canSave="!saving"
		:saving="saving"
		:saveButtonText="t('stackiq', 'Save EOL sync settings')"
		:showRefreshButton="true"
		:refreshing="loading"
		:refreshButtonText="t('stackiq', 'Refresh status')"
		@save="saveConfig"
		@refresh="loadAll">
		<template #header-actions>
			<NcButton variant="primary" :disabled="syncing" @click="triggerSync">
				<template #icon>
					<NcLoadingIcon v-if="syncing" :size="20" />
					<Sync v-else :size="20" />
				</template>
				{{ t('stackiq', 'Sync now') }}
			</NcButton>
		</template>

		<!-- Status banner. -->
		<NcNoteCard v-if="status.available" type="success">
			{{
				t(
					'stackiq',
					'Last run: {matched} matched, {skipped} skipped, at {time}.',
					{
						matched: status.matched,
						skipped: status.skipped,
						time: formattedLastRunAt,
					},
				)
			}}
		</NcNoteCard>
		<NcNoteCard v-else type="warning">
			{{
				t(
					'stackiq',
					'Feed unavailable: {reason}. Manual end-of-support entry, the EOL-approaching filter, the roadmap, and the notification rule keep working regardless.',
					{ reason: unavailableReasonLabel },
				)
			}}
		</NcNoteCard>

		<div class="eol-sync-settings">
			<div class="setting-group">
				<NcCheckboxRadioSwitch v-model="config.enabled" type="switch">
					{{ t('stackiq', 'Enable EOL feed sync') }}
				</NcCheckboxRadioSwitch>
				<p class="help-text">
					{{
						t(
							'stackiq',
							'When disabled, the matcher never reads or writes anything — the same as the feed being unavailable.',
						)
					}}
				</p>
			</div>

			<div class="setting-group">
				<h4>{{ t('stackiq', 'Source register and schemas') }}</h4>
				<p class="help-text">
					{{
						t(
							'stackiq',
							'Pre-filled with the names the Integriq endoflife-date-source change provisions. Change them if your instance uses different names, no code change required.',
						)
					}}
				</p>
				<NcTextField
					v-model="config.register"
					:label="t('stackiq', 'Register slug')"
					:disabled="!config.enabled" />
				<NcTextField
					v-model="config.productSchema"
					:label="t('stackiq', 'eol_product schema slug')"
					:disabled="!config.enabled" />
				<NcTextField
					v-model="config.cycleSchema"
					:label="t('stackiq', 'eol_cycle schema slug')"
					:disabled="!config.enabled" />
			</div>

			<div class="setting-group">
				<h4>{{ t('stackiq', 'Schedule') }}</h4>
				<NcTextField
					v-model="intervalMinutesInput"
					type="number"
					:label="t('stackiq', 'Sync interval (minutes)')"
					:disabled="!config.enabled" />
				<p class="help-text">
					{{
						t(
							'stackiq',
							'The scheduled background job re-runs the matcher at this interval; the minimum enforced interval is 5 minutes.',
						)
					}}
				</p>
			</div>
		</div>
	</AlwaysVisibleSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { defineComponent } from 'vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { apiRequest } from '../../../utils/adminApi.js'

/**
 * Reasons emitted by `EolSyncService::degrade()`, mapped to a translated
 * label. Falls back to the raw reason code for a reason not in this map
 * (forward-compatible with a future degrade path).
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 */
const REASON_LABELS = {
	disabled: () => t('stackiq', 'EOL feed sync is disabled'),
	'not-yet-run': () => t('stackiq', 'not yet run'),
	'openregister-not-installed': () =>
		t('stackiq', 'OpenRegister is not installed'),
	'object-service-unavailable': () =>
		t('stackiq', 'OpenRegister is not currently reachable'),
	'module-schema-not-configured': () =>
		t('stackiq', 'the module/moduleVersie schema is not configured yet'),
	'eol-register-or-schema-not-found': () =>
		t(
			'stackiq',
			'the configured register or schema could not be found. Is the Integriq endoflife-date-source change installed?',
		),
}

/**
 * EOL feed sync settings admin section.
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 */
export default defineComponent({
	name: 'EolSyncSettings',
	components: {
		AlwaysVisibleSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Sync,
	},

	/**
	 * @return {object} Component data.
	 */
	data() {
		return {
			loading: false,
			saving: false,
			syncing: false,
			config: {
				enabled: false,
				register: '',
				productSchema: '',
				cycleSchema: '',
				intervalSeconds: 86400,
			},

			status: {
				available: false,
				reason: 'not-yet-run',
				matched: 0,
				skipped: 0,
				lastRunAt: null,
			},
		}
	},

	computed: {
		/**
		 * The interval, presented/edited in whole minutes rather than raw
		 * seconds — friendlier admin input.
		 *
		 * @return {string} The interval in minutes, as a string for NcTextField.
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
		 */
		intervalMinutesInput: {
			/**
			 * @return {string} The interval in minutes, as a string for NcTextField.
			 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
			 */
			get() {
				return String(
					Math.max(
						1,
						Math.round((this.config.intervalSeconds || 86400) / 60),
					),
				)
			},

			/**
			 * @param {string} value The new interval in minutes.
			 * @return {void}
			 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
			 */
			set(value) {
				const minutes = parseInt(value, 10)
				if (Number.isFinite(minutes) && minutes > 0) {
					this.config.intervalSeconds = minutes * 60
				}
			},
		},

		/**
		 * A human-readable last-run timestamp, or a placeholder when the
		 * feature has never run.
		 *
		 * @return {string} The formatted timestamp.
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
		 */
		formattedLastRunAt() {
			if (!this.status.lastRunAt) {
				return t('stackiq', 'never')
			}
			try {
				return new Date(this.status.lastRunAt).toLocaleString()
			} catch (e) {
				return this.status.lastRunAt
			}
		},

		/**
		 * The translated label for the current unavailability reason
		 * (design.md Decision 6 — distinguishes "not configured" from
		 * "configured but nothing matched yet").
		 *
		 * @return {string} The translated reason label.
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
		 */
		unavailableReasonLabel() {
			const reason = this.status.reason || 'not-yet-run'
			const labelFn = REASON_LABELS[reason]
			return labelFn ? labelFn() : reason
		},
	},

	/**
	 * Load the config and status on mount.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	async created() {
		await this.loadAll()
	},

	methods: {
		t,

		/**
		 * Load both the configuration and the current status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
		 */
		async loadAll() {
			this.loading = true
			try {
				await Promise.all([this.loadConfig(), this.loadStatus()])
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the EOL sync configuration from the admin endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
		 */
		async loadConfig() {
			try {
				const data = await apiRequest('eol-sync/config')
				if (data && data.config) {
					this.config = { ...this.config, ...data.config }
				}
			} catch (error) {
				showError(
					t('stackiq', 'Could not load EOL sync configuration')
						+ ': '
						+ error.message,
				)
			}
		},

		/**
		 * Load the last-recorded EOL sync status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
		 */
		async loadStatus() {
			try {
				const data = await apiRequest('eol-sync/status')
				if (data && data.status) {
					this.status = { ...this.status, ...data.status }
				}
			} catch (error) {
				showError(
					t('stackiq', 'Could not load EOL sync status')
						+ ': '
						+ error.message,
				)
			}
		},

		/**
		 * Persist the edited configuration.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
		 */
		async saveConfig() {
			this.saving = true
			try {
				const data = await apiRequest('eol-sync/config', {
					method: 'POST',
					body: this.config,
				})
				if (data && data.config) {
					this.config = { ...this.config, ...data.config }
				}
				showSuccess(t('stackiq', 'EOL sync settings saved'))
			} catch (error) {
				showError(
					t('stackiq', 'Could not save EOL sync settings')
						+ ': '
						+ error.message,
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Trigger a manual EOL sync run — invokes the exact same
		 * orchestration logic as the scheduled background job.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
		 */
		async triggerSync() {
			this.syncing = true
			try {
				const data = await apiRequest('eol-sync/trigger', { method: 'POST' })
				if (data && data.status) {
					this.status = { ...this.status, ...data.status }
				}
				if (this.status.available) {
					showSuccess(
						t(
							'stackiq',
							'EOL sync completed: {matched} matched, {skipped} skipped.',
							{
								matched: this.status.matched,
								skipped: this.status.skipped,
							},
						),
					)
				} else {
					showError(
						t('stackiq', 'EOL sync did not run: {reason}', {
							reason: this.unavailableReasonLabel,
						}),
					)
				}
			} catch (error) {
				showError(t('stackiq', 'EOL sync failed') + ': ' + error.message)
			} finally {
				this.syncing = false
			}
		},
	},
})
</script>

<style scoped>
.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0 0 0.5rem;
}

.eol-sync-settings {
	max-width: 640px;
}

.setting-group {
	margin-bottom: 1.5rem;
}

.setting-group h4 {
	margin-bottom: 0.25rem;
}
</style>
