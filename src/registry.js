// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// The v1 customComponents.js map is preserved alongside this file for
// backward-compat during the migration window. CnAppRoot accepts both
// props; the v2 renderer emits a one-shot deprecation warning when both
// are present and the manifest is v2.
//
// Resolution at runtime (v2 path):
//   1. Built-in widgets registry (CnWidgetGrid built-ins)
//   2. registry prop (this file) — keyed by widgetKey / target / appliesTo
//
// References:
//   - hydra ADR-036
//   - nextcloud-app-template scaffold-v2 (#44) — canonical layout
//   - procest #512 / launchpad #206 — first reference migrations

import ContractApprovalPanel from './components/contracts/ContractApprovalPanel.vue'
import OrganisationMergePanel from './components/organisations/OrganisationMergePanel.vue'
import ComplianceMatrixView from './views/ComplianceMatrixView.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import LifecycleRoadmapView from './views/LifecycleRoadmapView.vue'
import SoftwareCatalogSettingsPage from './views/settings/StackiqSettings.vue'

export default {
	// --- Flows (ADR-110 Decision 4). Only the SIDEBAR is an app component;
	//     the list and the canvas are the shared `flows` / `flow-detail`
	//     manifest page types. CnFlowSidebar has to mount in the NC app
	//     sidebar for the canvas to keep full width. ---
	FlowDetailSidebar: { kind: 'page', component: FlowDetailSidebar },

	// --- Lib gap: settings sub-section orchestration. ---
	// Multi-tab nav + ArchiMate status polling + register selector that
	// the lib's type:'settings' rich-section widgets can't express yet.
	SoftwareCatalogSettingsPage: {
		kind: 'page',
		component: SoftwareCatalogSettingsPage,
	},

	// --- Lib gap: dashboard widget extraction pending. ---

	// --- Lib gap: per-organisation lifecycle roadmap. ---
	LifecycleRoadmapView: {
		kind: 'page',
		component: LifecycleRoadmapView,
	},

	// --- Lib gap: filter-first compliance matrix (modules × standards). ---
	ComplianceMatrixView: {
		kind: 'page',
		component: ComplianceMatrixView,
	},

	// --- Cross-app decision seam: contract approval delegated to decidesk. ---
	// Resolved by CnObjectSidebar as a ContractDetail sidebar-tab `component`.
	ContractApprovalPanel: {
		kind: 'page',
		component: ContractApprovalPanel,
	},

	// --- Admin-triggered organisation-merge (VNG Softwarecatalogus #141). ---
	// Resolved by CnDetailPage as an OrganisatieDetail bodyWidget. Dry-run
	// preview + confirm dialog + execute; no built-in widget expresses a
	// cross-object relation re-pointing action.
	OrganisationMergePanel: {
		kind: 'page',
		component: OrganisationMergePanel,
	},
}
