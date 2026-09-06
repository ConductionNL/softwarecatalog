// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for stackiq's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages or sidebar tabs that
// don't fit one of the manifest's built-in types/widgets. Keep this
// file SHORT. Adding entries should require explicit justification in
// the design doc; deleting them is the right direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/stackiq-manifest-v1/design.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

import OrganisatieCard from './components/cards/OrganisatieCard.vue'
import ContractApprovalPanel from './components/contracts/ContractApprovalPanel.vue'
import OrganisationMergePanel from './components/organisations/OrganisationMergePanel.vue'
import ReviewsPanel from './components/reviews/ReviewsPanel.vue'
import SbomComponentsPanel from './components/sbom/SbomComponentsPanel.vue'
import VulnerabilityExposurePanel from './components/vulnerabilities/VulnerabilityExposurePanel.vue'
import ComplianceMatrixView from './views/ComplianceMatrixView.vue'
import FacetedCatalogIndexView from './views/FacetedCatalogIndexView.vue'
import KwetsbaarhedenView from './views/KwetsbaarhedenView.vue'
import LicensePostureView from './views/LicensePostureView.vue'
import LifecycleRoadmapView from './views/LifecycleRoadmapView.vue'
import PortfolioReportView from './views/organisaties/PortfolioReport.vue'
import StackiqSettingsPage from './views/settings/StackiqSettings.vue'
import SuitesIndexView from './views/suites/SuitesIndexView.vue'

export default {
	// OrganisatieCard — the bespoke card (inline contactpersoon toggle) used as
	// the `cardComponent` of the now-decomposed Organisaties type='index' page
	// (Phase 8). CnIndexPage's cardComponent config closed the prior lib gap.
	OrganisatieCard,

	// --- Lib gap: settings sub-section orchestration. ---
	// The lib's type='settings' rich-section widgets cover individual
	// widget rendering but not the multi-tab navigation pattern + ArchiMate
	// status polling + register selector that StackiqSettings.vue
	// orchestrates. Settings stays type='settings' but its single section
	// delegates to this custom component.
	StackiqSettingsPage,

	// --- Lib gap: dashboard widget extraction pending. ---

	// --- Lib gap: per-organisation lifecycle roadmap. ---
	// Gebruiken grouped by a DERIVED lifecycle phase (computed from dates, not
	// stored) with EOL badges + planned replacements is a portfolio view no
	// built-in index/detail type expresses. Stays custom until the lib grows a
	// grouped-timeline widget.
	LifecycleRoadmapView,

	// --- Lib gap: filter-first compliance matrix. ---
	// Modules × selected standard versions with verified/claimed/none cell
	// states is a cross-tabulation no built-in index/detail type expresses.
	// Stays a custom page until the lib grows a matrix/cross-tab widget.
	ComplianceMatrixView,

	// --- Cross-app decision seam: contract approval delegated to decidesk. ---
	// Read-only Approval panel rendered as a ContractDetail sidebar tab. The
	// approval/sign-off/renewal DECISION is raised in decidesk via the ADR-019
	// integration registry and projected back onto the contract; stackiq
	// owns no approval workflow. Stays a custom tab component because it surfaces
	// a cross-app outcome no built-in detail widget expresses.
	ContractApprovalPanel,

	// --- Admin-triggered organisation-merge (VNG Softwarecatalogus #141). ---
	// Dry-run preview + confirm dialog + execute for folding a source
	// organisation into a target (gemeentelijke herindeling /
	// leveranciersovername). Rendered as an OrganisatieDetail bodyWidget.
	// No built-in widget expresses a cross-object relation re-pointing action.
	OrganisationMergePanel,

	// --- Lib gap: CVSS-derived severity index + severity-band quick filters. ---
	// The Vulnerabilities index shows a DERIVED severity band (from cvssScore),
	// an affected-application count and an exposed-in-production-usage count, and
	// filters by severity band — none of which a built-in type='index' (schema
	// columns + exact-match quick filters) can express. Create/edit/delete reuse
	// the app's generic ObjectModal. Stays custom until the lib grows derived
	// columns + computed-range filters.
	KwetsbaarhedenView,

	// --- Read-time exposure join rendered as a KwetsbaarheidDetail sidebar tab. ---
	// "Which in-production usages are exposed to this CVE" is the join
	// kwetsbaarheid.modules → module ← gebruik → afnemer (in-production only),
	// computed on demand and never stored. No built-in detail widget expresses a
	// cross-schema relational join, so it stays a custom tab component.
	VulnerabilityExposurePanel,

	// --- Portfolio license posture (SAM overview). ---
	// Open-source vs closed-source share of the in-production portfolio +
	// per-vendor rollup (deployments + licence mix + cost CONSUMED from
	// contract-administration) + per-organisation open-source-first report — all
	// derived at query time, weighted by in-production deployment. A read-only
	// aggregation dashboard no built-in index/detail type expresses; stays custom
	// until the lib grows a declarative aggregation/rollup widget.
	LicensePostureView,

	// --- Lib gap: live GEMMA-dimension facet counts on the module/dienst index pages. ---
	// CnIndexPage's own embedded `sidebar.enabled` facet machinery treats every
	// active-filter key as a directly-filterable schema field and applies it
	// verbatim to the self-fetch object-list query. `domein`/`applicatieservice`
	// are not module/dienst fields at all (they live on the linked `element`
	// object) and `referentiecomponent`/`standaard` are exposed here by display
	// NAME, not the identifiers the schema stores — feeding them through that
	// path would break the list. Stays a custom page (wrapping `CnFacetSidebar`
	// + a standalone `CnIndexPage` narrowed via `{ id: matchedObjectIds }`) until
	// the lib grows a facet-sidebar mode whose counts/narrowing are computed by
	// an external, non-schema-field aggregation (see FacetedCatalogIndexView.vue).
	FacetedCatalogIndexView,
	// --- Lib gap: composed backend-aggregate rationalization report. ---
	// GET /api/portfolio-report (PortfolioReportService) reads TIME quadrant
	// counts + EOL exposure + cloud-transition share + annualised cost as a
	// SINGLE bounded, organisation-scoped, RBAC-checked JSON payload plus a
	// CSV variant. Unlike LicensePostureView/LifecycleRoadmapView (client-side
	// derivation over full collections) this page is a thin renderer over
	// that endpoint — no built-in index/detail/dashboard type expresses a
	// fetched, pre-aggregated multi-metric report with a CSV export button.
	// @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
	PortfolioReportView,
	// --- SBOM (Software Bill of Materials) import — Components tab. ---
	// ModuleversieDetail sidebar tab: the imported sbomComponent list
	// (name/version/purl/licenses), summary counts, an upload control, and a
	// render-time vulnerability-match join (sbomVulnerabilityMatch.js) vs the
	// kwetsbaarheid register. No built-in detail widget expresses an upload
	// flow + cross-schema read-time match, so it stays a custom tab component.
	SbomComponentsPanel,

	// --- Lib gap: the guided suite-creation wizard's action button. ---
	// A declarative `type: index` page has no slot to inject the multi-step
	// "New suite" wizard trigger alongside the generic single-form create
	// button, so this stays a custom page. `CnIndexPage` itself still
	// self-fetches (register="stackiq" schema="suite") exactly as a
	// declarative type:index page would.
	// @spec openspec/specs/suite-wizard/spec.md
	SuitesIndexView,
	// --- Ratings & reviews (stackiq#375). ---
	// Approved-only aggregate (average + count) + a bounded approved-review
	// list, computed server-side by ReviewService, plus the "Write a review"
	// action opening SubmitReviewModal.vue. No built-in body widget
	// expresses a moderated, authored, cross-schema aggregate + submit
	// flow, so it stays a custom body-widget component (ModuleDetail's
	// bodyWidgets, same escape hatch as ContractApprovalPanel).
	// @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	ReviewsPanel,
}
