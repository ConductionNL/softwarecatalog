<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Approval')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="CheckCircleOutline" :size="20" />
		</template>
		<div class="contract-approval-panel">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading approval state')" />

			<template v-else>
				<!-- Delegation not configured: read-only notice, NO submit action. -->
				<NcNoteCard v-if="!configured" type="warning">
					{{
						t(
							'stackiq',
							'Approval delegation is not configured on this instance. Contract approval is handled by decidesk; ask an administrator to install and enable it.',
						)
					}}
				</NcNoteCard>

				<!-- Read-only projected approval state. -->
				<div class="contract-approval-panel__state">
					<span class="contract-approval-panel__label">{{
						t('stackiq', 'Approval state')
					}}</span>
					<CnStatusBadge
						:status="approvalStateLabel"
						:variant="approvalStateVariant" />
				</div>

				<p v-if="decisionId" class="contract-approval-panel__decision">
					{{ t('stackiq', 'Decision reference') }}:
					<code>{{ decisionId }}</code>
				</p>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<!-- Actions (hidden entirely when delegation is not configured). -->
				<div v-if="configured" class="contract-approval-panel__actions">
					<NcButton
						v-if="canSubmitApproval"
						variant="primary"
						:disabled="busy"
						@click="submit(false)">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ t('stackiq', 'Submit for approval') }}
					</NcButton>

					<NcButton
						v-if="canSubmitRenewal"
						variant="primary"
						:disabled="busy"
						@click="submit(true)">
						<template #icon>
							<Autorenew :size="20" />
						</template>
						{{ t('stackiq', 'Submit renewal') }}
					</NcButton>
				</div>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnStatusBadge, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Autorenew from 'vue-material-design-icons/Autorenew.vue'
import Check from 'vue-material-design-icons/Check.vue'

/**
 * @class ContractApprovalPanel
 * @module Components/Contracts
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Read-only Approval panel for the ContractDetail page sidebar. It surfaces the
 * `approvalState` PROJECTION of the decidesk outcome and offers the
 * "Submit for approval" / "Submit renewal" / "Refresh outcome" actions. The
 * submit actions are hidden when no decidesk endpoint resolves
 * ("approval delegation not configured") so no fail-open path exists. The
 * `In onderhandeling -> Actief` transition is NEVER performed here — it is a
 * projection of an approved decidesk outcome, applied server-side.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
export default {
	name: 'ContractApprovalPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CnStatusBadge,
		CnWidgetWrapper,
		CnIcon,
		Check,
		Autorenew,
	},

	props: {
		/**
		 * The contract OR object uuid (passed by CnObjectSidebar as `objectId`).
		 *
		 * @type {string}
		 */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * The contract register slug (passed by CnObjectSidebar).
		 *
		 * @type {string}
		 */
		register: {
			type: String,
			default: 'stackiq',
		},

		/**
		 * The contract schema slug (passed by CnObjectSidebar).
		 *
		 * @type {string}
		 */
		schema: {
			type: String,
			default: 'catalogContract',
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			configured: false,
			approvalState: 'none',
			status: '',
			decisionId: '',
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the contract may be submitted for first approval.
		 *
		 * @return {boolean} True for an In onderhandeling contract not already pending.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		canSubmitApproval() {
			return (
				this.status === 'In negotiation'
				&& (this.approvalState === 'none'
					|| this.approvalState === 'rejected')
			)
		},

		/**
		 * Whether the contract may be submitted for renewal.
		 *
		 * @return {boolean} True for an expiring/Verlopen contract not already pending.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		canSubmitRenewal() {
			return this.status === 'Expired' && this.approvalState !== 'pending'
		},

		/**
		 * Human-readable label for the projected approval state.
		 *
		 * @return {string} The translated approvalState label.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		approvalStateLabel() {
			const map = {
				none: t('stackiq', 'Not submitted'),
				pending: t('stackiq', 'Pending decision'),
				approved: t('stackiq', 'Approved'),
				rejected: t('stackiq', 'Rejected'),
			}
			return map[this.approvalState] || this.approvalState
		},

		/**
		 * Badge variant for the projected approval state.
		 *
		 * @return {string} The CnStatusBadge variant for the approvalState.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		approvalStateVariant() {
			const map = {
				none: 'default',
				pending: 'warning',
				approved: 'success',
				rejected: 'error',
			}
			return map[this.approvalState] || 'default'
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		/**
		 * Load delegation config + the contract's projected approval fields.
		 *
		 * @return {Promise<void>} Resolves once config + contract are loaded.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const configUrl = generateUrl(
					'/apps/stackiq/api/contracts/approval/config',
				)
				const { data: config } = await axios.get(configUrl)
				this.configured = Boolean(config.configured)
				await this.loadContract()
			} catch (e) {
				this.error = t('stackiq', 'Could not load the approval state.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the contract object to pull status + projected approval fields.
		 *
		 * @return {Promise<void>} Resolves once the contract fields are read.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		async loadContract() {
			if (!this.objectId) {
				return
			}
			const url = generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}',
				{
					register: this.register,
					schema: this.schema,
					id: String(this.objectId),
				},
			)
			const { data } = await axios.get(url)
			const obj =
				data && data['@self'] !== undefined ? data : data.object || data
			this.status = obj.status || ''
			this.approvalState = obj.approvalState || 'none'
			this.decisionId = obj.approvalDecisionId || ''
		},

		/**
		 * Submit the contract for approval (false) or renewal (true). Fail-closed:
		 * on error the contract stays In onderhandeling and a visible error shows.
		 *
		 * @param {boolean} isRenewal Whether to submit a renewal decision.
		 * @return {Promise<void>} Resolves once the submit completes or fails closed.
		 * @spec openspec/specs/contract-decision-delegation/spec.md
		 */
		async submit(isRenewal) {
			this.busy = true
			this.error = ''
			const path = isRenewal ? 'renewal' : 'submit'
			try {
				const url = generateUrl(
					'/apps/stackiq/api/contracts/{id}/approval/{path}',
					{ id: String(this.objectId), path },
				)
				const { data } = await axios.post(url)
				this.approvalState = data.approvalState || 'pending'
				this.decisionId = data.decisionId || this.decisionId
				showSuccess(
					t('stackiq', 'Contract submitted to decidesk for a decision.'),
				)
			} catch (e) {
				// Fail-closed: the contract stays In onderhandeling.
				const msg =
					e?.response?.data?.message
					|| t(
						'stackiq',
						'Submitting the contract failed; it remains in negotiation.',
					)
				this.error = msg
				showError(msg)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.contract-approval-panel {
	/* No own padding — CnWidgetWrapper's content area already supplies it. */
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.contract-approval-panel__state {
	display: flex;
	align-items: center;
	gap: 8px;
}

.contract-approval-panel__label {
	font-weight: 600;
}

.contract-approval-panel__decision code {
	font-size: 0.9em;
}

.contract-approval-panel__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
