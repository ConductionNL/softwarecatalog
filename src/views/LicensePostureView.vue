<template>
	<div class="postureView">
		<div class="pv-header">
			<h2 class="pv-title">
				{{ t('stackiq', 'License posture') }}
			</h2>
			<p class="pv-intro">
				{{
					t(
						'stackiq',
						'Open-source vs closed-source posture of the applications you actually run, weighted by in-production deployment — with per-vendor and per-organisation breakdowns.',
					)
				}}
			</p>
			<NcButton
				variant="tertiary"
				:aria-label="t('stackiq', 'Refresh data')"
				@click="loadData">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('stackiq', 'Refresh') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="40" class="pv-loading" />

		<template v-else>
			<!-- Portfolio posture -->
			<section class="pv-section" data-testid="posture-portfolio">
				<h3 class="pv-sectionTitle">
					{{ t('stackiq', 'Portfolio posture') }}
				</h3>
				<div class="pv-kpis">
					<div class="pv-kpi">
						<span class="pv-kpiValue">{{ openSharePct }}</span>
						<span class="pv-kpiLabel">{{
							t('stackiq', 'Open-source share (in production)')
						}}</span>
					</div>
					<div class="pv-kpi">
						<span class="pv-kpiValue">{{ portfolio.open }}</span>
						<span class="pv-kpiLabel">{{
							t('stackiq', 'Open source')
						}}</span>
					</div>
					<div class="pv-kpi">
						<span class="pv-kpiValue">{{ portfolio.closed }}</span>
						<span class="pv-kpiLabel">{{
							t('stackiq', 'Closed source')
						}}</span>
					</div>
					<div class="pv-kpi">
						<span class="pv-kpiValue">{{ portfolio.unknown }}</span>
						<span class="pv-kpiLabel">{{
							t('stackiq', 'Unknown')
						}}</span>
					</div>
				</div>
			</section>

			<!-- Per-vendor rollup -->
			<section class="pv-section" data-testid="posture-vendor">
				<h3 class="pv-sectionTitle">
					{{ t('stackiq', 'Per-vendor rollup') }}
				</h3>
				<NcEmptyContent
					v-if="vendorRows.length === 0"
					:name="t('stackiq', 'No in-production deployments')" />
				<table v-else class="pv-table">
					<thead>
						<tr>
							<th scope="col">{{ t('stackiq', 'Vendor') }}</th>
							<th scope="col">
								{{ t('stackiq', 'Deployments') }}
							</th>
							<th scope="col">{{ t('stackiq', 'Open') }}</th>
							<th scope="col">{{ t('stackiq', 'Closed') }}</th>
							<th scope="col">
								{{ t('stackiq', 'Unknown') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'Annual cost') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in vendorRows"
							:key="row.vendorId"
							data-testid="posture-vendor-row">
							<td>{{ row.vendorName }}</td>
							<td>{{ row.deployments }}</td>
							<td>{{ row.mix.openCount }}</td>
							<td>{{ row.mix.closedCount }}</td>
							<td>{{ row.mix.unknownCount }}</td>
							<td>{{ row.costLabel }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Per-organisation report -->
			<section class="pv-section" data-testid="posture-org">
				<h3 class="pv-sectionTitle">
					{{ t('stackiq', 'Per-organisation open-source-first report') }}
				</h3>
				<div class="pv-orgSelect">
					<NcSelect
						v-model="selectedOrg"
						:options="organisationOptions"
						:inputLabel="t('stackiq', 'Organisation')"
						:placeholder="t('stackiq', 'Select an organisation')"
						trackBy="uuid"
						label="label" />
				</div>
				<div
					v-if="selectedOrg"
					class="pv-orgReport"
					data-testid="posture-org-report">
					<div class="pv-kpis">
						<div class="pv-kpi">
							<span class="pv-kpiValue">{{ orgSharePct }}</span>
							<span class="pv-kpiLabel">{{
								t('stackiq', 'Open-source share')
							}}</span>
						</div>
						<div class="pv-kpi">
							<span class="pv-kpiValue">{{ orgPosture.open }}</span>
							<span class="pv-kpiLabel">{{
								t('stackiq', 'Open source')
							}}</span>
						</div>
						<div class="pv-kpi">
							<span class="pv-kpiValue">{{ orgPosture.closed }}</span>
							<span class="pv-kpiLabel">{{
								t('stackiq', 'Closed source')
							}}</span>
						</div>
					</div>
					<div v-if="orgClosedContributors.length" class="pv-contributors">
						<span class="pv-contribLabel"
							>{{ t('stackiq', 'Closed-source applications') }}:</span
						>
						<ul>
							<li v-for="c in orgClosedContributors" :key="c.id">
								{{ c.name }}
							</li>
						</ul>
					</div>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useLiveCollections } from '../composables/useLiveCollections.js'
import { objectStore } from '../store/store.js'
import {
	LICENSE_TYPE,
	perOrganisationPosture,
	perVendorRollup,
	portfolioPosture,
} from '../utils/licensePosture.js'
import { resolveUuid } from '../utils/lifecyclePhase.js'

/**
 * @class LicensePostureView
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Portfolio software-license posture (SAM overview): the open-source vs
 * closed-source share of the in-production application portfolio, a per-vendor
 * rollup (deployments + licence mix + annualised cost CONSUMED from
 * contract-administration, never re-derived), and a per-organisation
 * open-source-first report. All derived at query time from existing schemas via
 * the licensePosture util; nothing stored. No app-local aggregation engine.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export default {
	name: 'LicensePostureView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcEmptyContent,
		Refresh,
	},

	/**
	 * Live updates (nc-vue liveUpdatesPlugin, default-on since beta.212):
	 * subscribe to the collection scope of every type this view renders.
	 * Events are refetch hints — the plugin re-runs fetchCollection into
	 * the same getCollection() state the computeds read, so the view
	 * re-renders without extra bridging. Subscriptions are gated on the
	 * lazy type registration in loadData() and released on unmount.
	 *
	 * @return {object} Empty — the subscriptions are side-effect only
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	setup() {
		useLiveCollections(objectStore, [
			'module',
			'usage',
			'organization',
			'catalogContract',
		])
		return {}
	},

	data() {
		return {
			loading: true,
			selectedOrg: null,
		}
	},

	computed: {
		/**
		 * All module records.
		 *
		 * @return {Array} Module records.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		modules() {
			return objectStore.getCollection('module')?.results || []
		},

		/**
		 * All gebruik records.
		 *
		 * @return {Array} Gebruik records.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		usages() {
			return objectStore.getCollection('usage')?.results || []
		},

		/**
		 * All contract records (cost source; may be empty — cost degrades to empty).
		 *
		 * @return {Array} Contract records.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		contracts() {
			return objectStore.getCollection('catalogContract')?.results || []
		},

		/**
		 * Organisation UUID → display name.
		 *
		 * @return {object} Lookup map.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		organisatieIndex() {
			const index = {}
			for (const org of objectStore.getCollection('organization')?.results
				|| []) {
				const data = org.object || org
				const id = resolveUuid(org.uuid ?? org.id ?? org['@self']?.id ?? org)
				if (id) {
					index[id] = data.name || data.title || id
				}
			}
			return index
		},

		/**
		 * Module UUID → display name.
		 *
		 * @return {object} Lookup map.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		moduleNameIndex() {
			const index = {}
			for (const m of this.modules) {
				const data = m.object || m
				const id = resolveUuid(m.uuid ?? m.id ?? m['@self']?.id ?? m)
				if (id) {
					index[id] = data.name || data.title || id
				}
			}
			return index
		},

		/**
		 * Portfolio posture summary.
		 *
		 * @return {object} Posture summary.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		portfolio() {
			return portfolioPosture(this.modules, this.usages)
		},

		/**
		 * Open-source share as a percentage label.
		 *
		 * @return {string} e.g. "67%" or "—".
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		openSharePct() {
			return this.portfolio.openShare === null
				? '—'
				: `${Math.round(this.portfolio.openShare * 100)}%`
		},

		/**
		 * Per-vendor rollup rows resolved to display names + cost labels.
		 *
		 * @return {Array} Vendor rows.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		vendorRows() {
			return perVendorRollup(this.modules, this.usages, this.contracts)
				.map((row) => ({
					vendorId: row.vendorId,
					vendorName:
						this.organisatieIndex[row.vendorId]
						|| row.vendorId
						|| t('stackiq', 'Unknown vendor'),
					deployments: row.deployments,
					mix: {
						openCount: row.mix[LICENSE_TYPE.OPEN] || 0,
						closedCount: row.mix[LICENSE_TYPE.CLOSED] || 0,
						unknownCount: row.mix[LICENSE_TYPE.UNKNOWN] || 0,
					},
					costLabel:
						row.annualCost === null
							? '—'
							: this.formatCurrency(row.annualCost),
				}))
				.sort((a, b) => b.deployments - a.deployments)
		},

		/**
		 * Organisation options for the per-organisation report.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		organisationOptions() {
			return Object.entries(this.organisatieIndex).map(([uuid, label]) => ({
				uuid,
				label,
			}))
		},

		/**
		 * The selected organisation's posture.
		 *
		 * @return {object} Org posture.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		orgPosture() {
			if (!this.selectedOrg) {
				return {
					total: 0,
					open: 0,
					closed: 0,
					unknown: 0,
					openShare: null,
					closedContributors: [],
				}
			}
			return perOrganisationPosture(
				this.selectedOrg.uuid,
				this.modules,
				this.usages,
			)
		},

		/**
		 * The selected org's open-source share percentage label.
		 *
		 * @return {string} e.g. "50%" or "—".
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		orgSharePct() {
			return this.orgPosture.openShare === null
				? '—'
				: `${Math.round(this.orgPosture.openShare * 100)}%`
		},

		/**
		 * Closed-source contributors of the selected org, resolved to names.
		 *
		 * @return {Array<{id: string, name: string}>} Contributors.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		orgClosedContributors() {
			return this.orgPosture.closedContributors.map((id) => ({
				id,
				name: this.moduleNameIndex[id] || id,
			}))
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		t,

		/**
		 * Load the collections the posture depends on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		async loadData() {
			this.loading = true
			try {
				if (
					!objectStore.settings
					&& typeof objectStore.fetchSettings === 'function'
				) {
					await objectStore.fetchSettings()
				}
				await Promise.all([
					this.fetchType('module'),
					this.fetchType('usage'),
					this.fetchType('organization'),
					this.fetchType('catalogContract'),
				])
			} catch (error) {
				console.error('LicensePostureView: failed to load data', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one object type collection, registering the type if needed.
		 *
		 * @param {string} type Object type slug.
		 * @return {Promise<void>}
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		async fetchType(type) {
			if (
				typeof objectStore.registerObjectType === 'function'
				&& !objectStore.objectTypeRegistry?.[type]
			) {
				let cfg = null
				try {
					cfg = objectStore.getSchemaConfig?.(type)
				} catch (cfgError) {
					// getSchemaConfig throws when no schema/register resolves; fall through.
				}
				if (cfg?.register && cfg?.schema) {
					objectStore.registerObjectType(type, cfg.schema, cfg.register)
				}
			}
			if (typeof objectStore.fetchCollection === 'function') {
				await objectStore.fetchCollection(type, { _limit: 1000 })
			}
		},

		/**
		 * Format an annual-cost number as a euro label.
		 *
		 * @param {number} amount The amount.
		 * @return {string} Currency label.
		 * @spec openspec/specs/software-license-posture/spec.md
		 */
		formatCurrency(amount) {
			try {
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: 'EUR',
					maximumFractionDigits: 0,
				}).format(amount)
			} catch (error) {
				return `€ ${Math.round(amount)}`
			}
		},
	},
}
</script>

<style scoped>
.postureView {
	padding: 20px;
}

.pv-header {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.pv-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.pv-intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 72ch;
}

.pv-section {
	margin-bottom: 28px;
}

.pv-sectionTitle {
	margin: 0 0 12px;
	font-size: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
}

.pv-kpis {
	display: flex;
	gap: 24px;
	flex-wrap: wrap;
}

.pv-kpi {
	display: flex;
	flex-direction: column;
	min-width: 120px;
}

.pv-kpiValue {
	font-size: 28px;
	font-weight: 600;
}

.pv-kpiLabel {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.pv-table {
	width: 100%;
	border-collapse: collapse;
}

.pv-table th,
.pv-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.pv-orgSelect {
	max-width: 420px;
	margin-bottom: 16px;
}

.pv-contributors {
	margin-top: 12px;
}

.pv-contribLabel {
	font-weight: 600;
}

.pv-loading {
	margin: 40px auto;
	display: block;
}
</style>
