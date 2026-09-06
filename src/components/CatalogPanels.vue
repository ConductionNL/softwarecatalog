<template>
	<div class="catalogPanels">
		<NcNoteCard type="info" class="infoBox">
			<div class="infoBoxContent">
				<h3 class="infoBoxTitle">Beheer van Organisaties</h3>
				<p class="infoBoxText">
					Organisaties kunnen worden geaccepteerd en beheerd via de
					organisaties pagina. Het aanmaken en bewerken van gebruikers gaat
					ook via de organisatie pagina, omdat deze onderdeel zijn van
					organisaties.
				</p>
				<div class="infoBoxActions">
					<NcButton variant="primary" @click="navigateToOrganizations">
						<template #icon>
							<OfficeBuildingOutline :size="16" />
						</template>
						Ga naar Organisaties
					</NcButton>
				</div>
			</div>
		</NcNoteCard>

		<div class="statisticsTableContainer">
			<div class="statisticsTableHeader">
				<span class="lastUpdated"
					>Laatst bijgewerkt: {{ formatDate(new Date()) }}</span
				>
			</div>

			<table class="objectStatisticsTable">
				<thead>
					<tr>
						<th scope="col">Object Type</th>
						<th scope="col" class="countHeader">Count</th>
						<th scope="col" class="manageHeader">Manage</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="stat in firstTableStats"
						:key="stat.objectType"
						style="cursor: pointer"
						@click="navigateToSchema(stat.slug)">
						<td>{{ stat.objectType }}</td>
						<td class="countCell">
							{{ stat.count.toLocaleString() }}
						</td>
						<td class="manageCell">
							<NcButton
								v-if="stat.slug === 'organization'"
								size="small"
								variant="tertiary"
								@click.stop="navigateToObjectType(stat.slug)">
								<template #icon>
									<component
										:is="getIconForObjectType(stat.slug)"
										:size="16" />
								</template>
								Manage
							</NcButton>
							<span v-else class="disabledManage">
								<component
									:is="getIconForObjectType(stat.slug)"
									:size="16" />
								<span class="strikethrough">Manage</span>
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="statisticsTableContainer">
			<div class="statisticsTableHeader">
				<span class="lastUpdated"
					>Laatst bijgewerkt: {{ formatDate(new Date()) }}</span
				>
			</div>

			<table class="objectStatisticsTable">
				<thead>
					<tr>
						<th scope="col">Object Type</th>
						<th scope="col" class="countHeader">Count</th>
						<th scope="col" class="manageHeader">Manage</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="stat in secondTableStats"
						:key="stat.objectType"
						style="cursor: pointer"
						@click="navigateToSchema(stat.slug)">
						<td>{{ stat.objectType }}</td>
						<td class="countCell">
							{{ stat.count.toLocaleString() }}
						</td>
						<td class="manageCell">
							<NcButton
								v-if="stat.slug === 'organization'"
								size="small"
								variant="tertiary"
								@click.stop="navigateToObjectType(stat.slug)">
								<template #icon>
									<component
										:is="getIconForObjectType(stat.slug)"
										:size="16" />
								</template>
								Manage
							</NcButton>
							<span v-else class="disabledManage">
								<component
									:is="getIconForObjectType(stat.slug)"
									:size="16" />
								<span class="strikethrough">Manage</span>
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
// Icons
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { navigationStore, objectStore } from '../store/store.js'

/**
 * @class Dashboard
 * @module Views
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 2.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Dashboard view showing overview statistics and configuration status.
 * Rendered as the `catalog-panels` dashboard widget; the KPI tiles beside it
 * are declarative `stat` widgets in the manifest.
 */
export default {
	name: 'CatalogPanels',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		OfficeBuildingOutline,
		AccountMultiple,
		ApplicationCog,
		FileDocumentEdit,
		Cog,
		Refresh,
		DatabaseOutline,
	},

	data() {
		return {
			loading: true,
		}
	},

	computed: {
		/**
		 * Get object statistics for the table display
		 *
		 * @return {Array} Array of object statistics
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		objectStatistics() {
			const stats = []

			// Find the voorzieningen register from settings
			if (!objectStore.settings?.availableRegisters) {
				return stats
			}

			const voorzieningenRegister =
				objectStore.settings.availableRegisters.find(
					(register) => register.slug === 'stackiq',
				)

			if (!voorzieningenRegister?.schemas) {
				return stats
			}

			// Create statistics for each schema in the voorzieningen register
			voorzieningenRegister.schemas.forEach((schema) => {
				const collection = objectStore.getCollection(schema.slug)
				const count = collection?.results?.length || 0
				const pagination = objectStore.getPagination(schema.slug)
				const total = pagination?.total || count

				stats.push({
					register: voorzieningenRegister.title,
					objectType: schema.title,
					count: total,
					slug: schema.slug,
				})
			})

			// Sort by object type name for consistent display
			return stats.sort((a, b) => a.objectType.localeCompare(b.objectType))
		},

		/**
		 * Get first half of statistics for first table
		 *
		 * @return {Array} First half of statistics
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		firstTableStats() {
			const stats = this.objectStatistics
			const midPoint = Math.ceil(stats.length / 2)
			return stats.slice(0, midPoint)
		},

		/**
		 * Get second half of statistics for second table
		 *
		 * @return {Array} Second half of statistics
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		secondTableStats() {
			const stats = this.objectStatistics
			const midPoint = Math.ceil(stats.length / 2)
			return stats.slice(midPoint)
		},
	},

	async mounted() {
		await this.loadDashboardData()
	},

	methods: {
		/**
		 * Load dashboard data
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		async loadDashboardData() {
			this.loading = true
			try {
				// Ensure settings are loaded (avoid duplicate calls)
				if (!objectStore.settings && !objectStore.isLoading('settings')) {
					await objectStore.fetchSettings()
				}

				// Load data for all registered object types
				const registeredTypes = Object.keys(
					objectStore.objectTypeRegistry || {},
				)
				await Promise.all(
					registeredTypes.map(async (objectType) => {
						try {
							await objectStore.fetchCollection(objectType, {
								_limit: 1,
							}) // Just get pagination info
						} catch (error) {
							console.warn(
								`Failed to fetch ${objectType} collection:`,
								error,
							)
						}
					}),
				)
			} catch (error) {
				console.error('Failed to load dashboard data:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get schema configuration for object type
		 *
		 * @param {string} objectType - Object type slug
		 * @return {object | null} Schema configuration
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		getSchemaConfig(objectType) {
			if (!objectStore.settings?.schemaConfigurations) {
				return null
			}

			// Look for schema in all registers
			const schemas = objectStore.settings.schemaConfigurations
			for (const register of Object.keys(schemas)) {
				const schemaData = schemas[register]?.schemas?.[objectType]
				if (schemaData) {
					return schemaData
				}
			}
			return null
		},

		/**
		 * Get icon component for specific object type
		 *
		 * @param {string} objectType - Object type slug
		 * @return {object} Vue icon component
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		getIconForObjectType(objectType) {
			const iconMap = {
				organization: OfficeBuildingOutline,
				contactPerson: AccountMultiple,
				voorziening: ApplicationCog,
				contract: FileDocumentEdit,
				suite: ApplicationCog,
				module: ApplicationCog,
				koppeling: ApplicationCog,
				service: ApplicationCog,
				standard: FileDocumentEdit,
				compliancy: FileDocumentEdit,
				kwetsbaarheid: FileDocumentEdit,
				beoordeling: FileDocumentEdit,
			}
			return iconMap[objectType] || DatabaseOutline
		},

		/**
		 * Navigate to object type management page
		 *
		 * @param {string} objectType - Object type slug to navigate to
		 * @return {void}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		navigateToObjectType(objectType) {
			// Handle special cases for plural routing
			const routeMap = {
				organization: 'organisaties',
				contactPerson: 'contactpersonen',
				voorziening: 'voorzieningen',
				contract: 'contracten',
			}

			const route = routeMap[objectType] || `${objectType}s`
			navigationStore.setSelected(route)
		},

		/**
		 * Navigate to organizations page
		 *
		 * @return {void}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		navigateToOrganizations() {
			navigationStore.setSelected('organisaties')
		},

		/**
		 * Navigate to the index page that corresponds to an OR schema slug.
		 * Covers all voorzieningen schemas so every count row is clickable.
		 *
		 * @param {string} slug - OR schema slug (e.g. 'organization', 'standard').
		 * @return {void}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		navigateToSchema(slug) {
			const slugToSelected = {
				organization: 'organisaties',
				contactPerson: 'contactpersonen',
				contract: 'contracten',
				standard: 'standards',
				compliancy: 'komplianties',
				moduleversie: 'moduleversies',
			}
			const selected = slugToSelected[slug]
			if (selected) {
				navigationStore.setSelected(selected)
			}
		},

		/**
		 * Navigate to configuration page - opens admin settings in new tab
		 *
		 * @param {string} _route - Ignored; the destination is the fixed admin
		 *   settings URL below. Kept so existing call sites still type-check.
		 * @return {void}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		navigateToConfiguration(_route) {
			const settingsUrl = `${window.location.protocol}//${window.location.host}/index.php/settings/admin/stackiq`
			window.open(settingsUrl, '_blank')
		},

		/**
		 * Format object type name for display
		 *
		 * @param {string} objectType - The object type slug
		 * @return {string} Formatted object type name
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		formatObjectTypeName(objectType) {
			// Convert camelCase/kebab-case to proper case
			return objectType
				.replace(/([a-z])([A-Z])/g, '$1 $2')
				.replace(/[-_]/g, ' ')
				.split(' ')
				.map((word) => word.charAt(0).toUpperCase() + word.slice(1))
				.join(' ')
		},

		/**
		 * Format date for display
		 *
		 * Renders as `17/08/2026, 08:33`. `toLocaleDateString` is correct here
		 * even though the result carries a time: explicit `hour`/`minute`
		 * options are honoured (ECMA-402 ToDateTimeOptions only supplies
		 * date-part DEFAULTS when none are given), so this is not the
		 * "toLocaleDateString silently drops the time" trap it resembles.
		 *
		 * A trailing `.replace(',', ',')` was removed here. It replaced the
		 * comma with itself — a no-op, flagged as js/identity-replacement.
		 * It was born in that identical form (5c33f0b, "Working on the detail
		 * pages"), so no working behaviour was ever lost and no intent is
		 * recorded anywhere to recover. Deleting it is byte-for-byte
		 * output-preserving, verified against the string above; guessing at
		 * `.replace(',', '')` would have invented a UI change nothing asked for.
		 *
		 * @param {Date} date - Date to format
		 * @return {string} Formatted date string
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		formatDate(date) {
			return date.toLocaleDateString('en-GB', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * Refresh all data - force reload settings and all collections
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		async refreshAllData() {
			console.info('Dashboard: Refreshing all data...')
			this.loading = true

			try {
				// Force reload settings (even if already loaded)
				console.info('Dashboard: Force reloading settings...')
				await objectStore.fetchSettings(true) // Force refresh

				// Wait for object types to be registered
				await new Promise((resolve) => setTimeout(resolve, 500))

				// Refresh all registered object collections
				const registeredTypes = Object.keys(
					objectStore.objectTypeRegistry || {},
				)
				console.info(
					'Dashboard: Refreshing collections for:',
					registeredTypes,
				)

				if (registeredTypes.length > 0) {
					await Promise.all(
						registeredTypes.map(async (objectType) => {
							try {
								console.info(
									`Dashboard: Refreshing ${objectType} collection...`,
								)
								await objectStore.fetchCollection(objectType, {
									_limit: 1,
								}) // Just get pagination info
							} catch (error) {
								console.warn(
									`Failed to refresh ${objectType} collection:`,
									error,
								)
							}
						}),
					)
				} else {
					console.warn(
						'Dashboard: No object types registered after settings refresh',
					)
				}

				console.info('Dashboard: All data refreshed successfully')
			} catch (error) {
				console.error('Dashboard: Failed to refresh all data:', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
/* Info Box Styles */
.infoBox {
	margin: 0;
}

.infoBoxContent {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.infoBoxTitle {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.infoBoxText {
	margin: 0;
	font-size: 14px;
	line-height: 1.5;
}

.infoBoxActions {
	display: flex;
	justify-content: flex-start;
	margin-top: 8px;
}

/* Statistics Table Styles */
.statisticsTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.statisticsTableHeader {
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: flex-end;
	background: var(--color-background-dark);
}

.lastUpdated {
	font-size: 12px;
	color: var(--color-text-lighter);
}

.objectStatisticsTable {
	width: 100%;
	border-collapse: collapse;
}

.objectStatisticsTable th {
	text-align: left;
	padding: 16px;
	background: var(--color-background-hover);
	font-weight: 600;
	color: var(--color-text-darker);
	border-bottom: 1px solid var(--color-border);
}

.countHeader,
.manageHeader {
	text-align: right;
}

.objectStatisticsTable td {
	padding: 16px;
	border-bottom: 1px solid var(--color-border-dark);
}

.objectStatisticsTable tbody tr:hover {
	background: var(--color-background-hover);
}

.objectStatisticsTable tbody tr:last-child td {
	border-bottom: none;
}

.countCell {
	font-weight: 600;
	text-align: right;
}

.manageCell {
	text-align: right;
}

.disabledManage {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	opacity: 0.6;
}

.strikethrough {
	text-decoration: line-through;
}
</style>
