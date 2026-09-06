<?php

declare(strict_types=1);

/**
 * Stackiq Routes Configuration
 *
 * This file defines the API routes for the Stackiq application.
 *
 * @category Configuration
 * @package  OCA\Stackiq
 * @version  1.0.0
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 */

return [
    'routes' => [
        // Dashboard routes
        // First-time setup wizard (ADR-042) - the standard CnSetupWizard contract.
        ['name' => 'setup#status',    'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z0-9\\-]+']],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',           'verb' => 'POST'],
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Contract approval delegation to decidesk (in-process IEventDispatcher; fail-closed).
        // Outcome is projected by DecisionConcludedListener, not an HTTP callback.
        // @spec openspec/changes/stackiq-delegation-via-events/specs/contract-decision-delegation/spec.md
        ['name' => 'contractApproval#config', 'url' => '/api/contracts/approval/config', 'verb' => 'GET'],
        ['name' => 'contractApproval#submit', 'url' => '/api/contracts/{contractUuid}/approval/submit', 'verb' => 'POST'],
        ['name' => 'contractApproval#submitRenewal', 'url' => '/api/contracts/{contractUuid}/approval/renewal', 'verb' => 'POST'],

        // Core Settings API routes (minimal, for basic app functionality)
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        // Canonical AppHost write verb. `settings#create` (POST, above) is the
        // legacy alias and delegates to `update()`; both are kept.
        // @spec openspec/specs/method-decomposition/spec.md#requirement-settingscontroller-settings-crud-endpoints-req-decomp-013
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'GET'],
        ['name' => 'settings#initialize', 'url' => '/api/settings/initialize', 'verb' => 'POST'],
        ['name' => 'settings#status', 'url' => '/api/settings/status', 'verb' => 'GET'],
        ['name' => 'settings#stats', 'url' => '/api/settings/stats', 'verb' => 'GET'],
        ['name' => 'settings#autoConfigure', 'url' => '/api/settings/auto-configure', 'verb' => 'POST'],
        ['name' => 'settings#consolidatedAutoConfigure', 'url' => '/api/settings/consolidated-auto-configure', 'verb' => 'POST'],
        ['name' => 'settings#debug', 'url' => '/api/settings/debug', 'verb' => 'GET'],

        // Separate endpoints for performance optimization
        ['name' => 'settings#getArchiMateSettings', 'url' => '/api/settings/archimate', 'verb' => 'GET'],
        ['name' => 'settings#getObjectCounts', 'url' => '/api/settings/objects', 'verb' => 'GET'],

        // Organization synchronization routes
        ['name' => 'settings#getSyncStatus', 'url' => '/api/settings/sync-status', 'verb' => 'GET'],
        ['name' => 'settings#performSync', 'url' => '/api/settings/sync', 'verb' => 'POST'],

        // Heartbeat route for keeping connections alive during long operations
        ['name' => 'settings#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'POST'],

        // Version and import management routes
        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
        ['name' => 'settings#manualImport', 'url' => '/api/settings/import', 'verb' => 'POST'],
        ['name' => 'settings#forceUpdate', 'url' => '/api/settings/force-update', 'verb' => 'POST'],
        ['name' => 'settings#resetAutoConfig', 'url' => '/api/settings/reset-auto-config', 'verb' => 'POST'],

        // Email management routes
        ['name' => 'settings#sendTestEmail', 'url' => '/api/email/test', 'verb' => 'POST'],
        ['name' => 'settings#testEmailConnection', 'url' => '/api/email/test-connection', 'verb' => 'POST'],
        ['name' => 'settings#getEmailSettings', 'url' => '/api/settings/email', 'verb' => 'GET'],
        ['name' => 'settings#updateEmailSettings', 'url' => '/api/settings/email', 'verb' => 'POST'],

        // Email template management routes
        ['name' => 'settings#getEmailTemplates', 'url' => '/api/email/templates', 'verb' => 'GET'],
        ['name' => 'settings#getEmailTemplate', 'url' => '/api/email/templates/{templateName}', 'verb' => 'GET'],
        ['name' => 'settings#updateEmailTemplate', 'url' => '/api/email/templates/{templateName}', 'verb' => 'POST'],
        ['name' => 'settings#getEmailTemplateDefault', 'url' => '/api/email/templates/{templateName}/default', 'verb' => 'GET'],
        ['name' => 'settings#getEmailTemplateVariables', 'url' => '/api/email/templates/{templateName}/variables', 'verb' => 'GET'],

        // Note: /api/health is served by settings#status above

        // Configuration cache management
        ['name' => 'settings#clearCache', 'url' => '/api/settings/clear-cache', 'verb' => 'POST'],

        // SBOM (Software Bill of Materials) import routes — CycloneDX/SPDX
        // upload scoped to a single moduleVersie, and its status.
        // @spec openspec/specs/sbom-import/spec.md
        ['name' => 'sbom#importSbom', 'url' => '/api/moduleversies/{moduleVersieUuid}/sbom', 'verb' => 'POST'],
        ['name' => 'sbom#getSbomImportStatus', 'url' => '/api/moduleversies/{moduleVersieUuid}/sbom', 'verb' => 'GET'],

        // ArchiMate import/export routes
        ['name' => 'settings#importArchiMate', 'url' => '/api/archimate/import', 'verb' => 'POST'],
        ['name' => 'settings#exportArchiMate', 'url' => '/api/archimate/export', 'verb' => 'POST'],
        ['name' => 'settings#exportOrgArchiMate', 'url' => '/api/archimate/export/organization/{organizationUuid}', 'verb' => 'GET'],
        ['name' => 'settings#downloadArchiMate', 'url' => '/api/archimate/download/{fileName}', 'verb' => 'GET'],

        // ArchiMate status management routes (status reading is via main settings endpoint)
        ['name' => 'settings#clearArchiMateImportStatus', 'url' => '/api/archimate/status/import/clear', 'verb' => 'POST'],
        ['name' => 'settings#cancelArchiMateImport', 'url' => '/api/archimate/import/cancel', 'verb' => 'POST'],
        ['name' => 'settings#killArchiMateImport', 'url' => '/api/archimate/import/kill', 'verb' => 'POST'], // deprecated
        ['name' => 'settings#clearArchiMateExportStatus', 'url' => '/api/archimate/status/export/clear', 'verb' => 'POST'],

        ['name' => 'settings#testArchiMateRoundTrip', 'url' => '/api/archimate/test-round-trip', 'verb' => 'POST'],

        // User Groups management routes
        ['name' => 'settings#getGenericUserGroups', 'url' => '/api/settings/user-groups/generic', 'verb' => 'GET'],
        ['name' => 'settings#setGenericUserGroups', 'url' => '/api/settings/user-groups/generic', 'verb' => 'POST'],
        ['name' => 'settings#getOrganizationAdminGroups', 'url' => '/api/settings/user-groups/organization-admin', 'verb' => 'GET'],
        ['name' => 'settings#setOrganizationAdminGroups', 'url' => '/api/settings/user-groups/organization-admin', 'verb' => 'POST'],
        ['name' => 'settings#getSuperUserGroups', 'url' => '/api/settings/user-groups/super-user', 'verb' => 'GET'],
        ['name' => 'settings#setSuperUserGroups', 'url' => '/api/settings/user-groups/super-user', 'verb' => 'POST'],
        ['name' => 'settings#getAllGroups', 'url' => '/api/settings/user-groups/all', 'verb' => 'GET'],

        // Progress streaming routes
        ['name' => 'settings#getProgress', 'url' => '/api/progress/{operationId}', 'verb' => 'GET'],
        ['name' => 'settings#streamProgress', 'url' => '/api/progress/{operationId}/stream', 'verb' => 'GET'],

        // ========================================================================
        // FOCUSED ENDPOINTS FOR PERFORMANCE OPTIMIZATION
        // ========================================================================

        // ArchiMate focused endpoints
        ['name' => 'settings#getArchiMateConfig', 'url' => '/api/archimate/config', 'verb' => 'GET'],
        ['name' => 'settings#updateArchiMateConfig', 'url' => '/api/archimate/config', 'verb' => 'POST'],
        ['name' => 'settings#getArchiMateConfig', 'url' => '/api/archimate/status', 'verb' => 'GET'],

        // Email focused endpoints
        ['name' => 'settings#getEmailConfig', 'url' => '/api/email/config', 'verb' => 'GET'],
        ['name' => 'settings#updateEmailConfig', 'url' => '/api/email/config', 'verb' => 'POST'],

        // AMEF focused endpoints
        ['name' => 'settings#getAmefConfig', 'url' => '/api/amef/config', 'verb' => 'GET'],
        ['name' => 'settings#updateAmefConfig', 'url' => '/api/amef/config', 'verb' => 'POST'],

        // Voorzieningen focused endpoints
        ['name' => 'settings#getVoorzieningenConfig', 'url' => '/api/voorzieningen/config', 'verb' => 'GET'],
        ['name' => 'settings#updateVoorzieningenConfig', 'url' => '/api/voorzieningen/config', 'verb' => 'POST'],

        // Objects focused endpoints (for object counts)
        ['name' => 'settings#getObjectsCounts', 'url' => '/api/objects/counts', 'verb' => 'GET'],
        ['name' => 'settings#getObjectsStatistics', 'url' => '/api/objects/statistics', 'verb' => 'GET'],

        // User Groups focused endpoints
        ['name' => 'settings#getUserGroupsConfig', 'url' => '/api/user-groups/config', 'verb' => 'GET'],
        ['name' => 'settings#updateUserGroupsConfig', 'url' => '/api/user-groups/config', 'verb' => 'POST'],

        // General Settings focused endpoints
        ['name' => 'settings#getGeneralConfig', 'url' => '/api/settings/general/config', 'verb' => 'GET'],
        ['name' => 'settings#updateGeneralConfig', 'url' => '/api/settings/general/config', 'verb' => 'POST'],

        // Organization Synchronization focused endpoints
        ['name' => 'settings#getSyncConfig', 'url' => '/api/settings/sync/config', 'verb' => 'GET'],
        ['name' => 'settings#updateSyncConfig', 'url' => '/api/settings/sync/config', 'verb' => 'POST'],
        ['name' => 'settings#syncOrganisations', 'url' => '/api/settings/sync/organisations', 'verb' => 'POST'],

        // User Profile endpoint
        ['name' => 'contactpersonen#getMe', 'url' => '/api/me', 'verb' => 'GET'],

        // Contactpersonen Management endpoints
        ['name' => 'contactpersonen#getContactpersonen', 'url' => '/api/contactpersonen/organisation/{organisationId}', 'verb' => 'GET'],
        ['name' => 'contactpersonen#getContactPersonsWithUserDetailsForOrganization', 'url' => '/api/contactpersonen/organisation/{organizationUuid}/with-user-details', 'verb' => 'GET'],
        ['name' => 'contactpersonen#convertToUser', 'url' => '/api/contactpersonen/{contactpersoonId}/convert-to-user', 'verb' => 'POST'],
        ['name' => 'contactpersonen#changePassword', 'url' => '/api/contactpersonen/change-password', 'verb' => 'POST'],
        ['name' => 'contactpersonen#updateUserGroups', 'url' => '/api/contactpersonen/update-groups', 'verb' => 'POST'],
        ['name' => 'contactpersonen#getUserInfo', 'url' => '/api/contactpersonen/{contactpersoonId}/user-info', 'verb' => 'GET'],
        ['name' => 'contactpersonen#getBulkUserInfo', 'url' => '/api/contactpersonen/bulk-user-info', 'verb' => 'POST'],
        ['name' => 'contactpersonen#getAvailableGroups', 'url' => '/api/contactpersonen/available-groups', 'verb' => 'GET'],
        ['name' => 'contactpersonen#disableUser', 'url' => '/api/contactpersonen/{contactpersoonId}/disable', 'verb' => 'POST'],
        ['name' => 'contactpersonen#enableUser', 'url' => '/api/contactpersonen/{contactpersoonId}/enable', 'verb' => 'POST'],

        // ========================================================================
        // VIEW API ENDPOINTS - ArchiMate Views with Enrichment Support
        // ========================================================================

        // View API endpoints for querying and enriching ArchiMate views
        ['name' => 'view#getAllViews', 'url' => '/api/views', 'verb' => 'GET'],
        ['name' => 'view#getApiDocumentation', 'url' => '/api/views/docs', 'verb' => 'GET'],
        ['name' => 'view#getView', 'url' => '/api/views/{viewId}', 'verb' => 'GET'],

        // ========================================================================
        // FACET API ENDPOINTS - GEMMA-dimension facet aggregation for the
        // module/dienst index pages (gemma-faceted-search)
        // ========================================================================

        // @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
        ['name' => 'facet#getFacets', 'url' => '/api/facets/{schema}', 'verb' => 'GET'],

        // ========================================================================
        // AANBOD API ENDPOINTS - Unified API for all aanbod types
        // ========================================================================

        // Aanbod API endpoints for managing offers (gebruik, dienst, module, koppeling)
        ['name' => 'aanbod#getAanbod', 'url' => '/api/aanbod', 'verb' => 'GET'],
        ['name' => 'aanbod#acceptAanbod', 'url' => '/api/aanbod/{uuid}/accept', 'verb' => 'PUT'],
        ['name' => 'aanbod#denyAanbod', 'url' => '/api/aanbod/{uuid}/deny', 'verb' => 'DELETE'],

        // OPEN-DATA PUBLISH ENDPOINTS — set/clear publicatiedatum (the live OR
        // RBAC publish gate). Authenticated + per-object ownership guard (IDOR-safe).
        ['name' => 'publication#publish', 'url' => '/api/publication/{objectType}/{uuid}/publish', 'verb' => 'PUT'],
        ['name' => 'publication#depublish', 'url' => '/api/publication/{objectType}/{uuid}/depublish', 'verb' => 'DELETE'],

        // ANONYMOUS REGISTRATION INTAKE — public, write-only to the moderation
        // queue (lands as registratiestatus=pending, no publicatiedatum → invisible
        // until an admin approves). Anti-spam rate-limited.
        ['name' => 'intake#submit', 'url' => '/api/intake/register', 'verb' => 'POST'],

        // REGISTRATION / REVIEW MODERATION / APPROVAL QUEUE — admin-gated
        // (AuthorizedAdminSetting). Selects organisatie (default) or
        // beoordeeling via the `type` query param — one generalised
        // mechanism, see ModerationService.
        ['name' => 'moderation#pending', 'url' => '/api/moderation/pending', 'verb' => 'GET'],
        ['name' => 'moderation#approve', 'url' => '/api/moderation/{uuid}/approve', 'verb' => 'POST'],
        ['name' => 'moderation#reject', 'url' => '/api/moderation/{uuid}/reject', 'verb' => 'POST'],

        // CATALOG RATINGS (stackiq#375) — authenticated review
        // submission (author/status always server-stamped, never from the
        // client) + public approved-only aggregate for module/dienst detail.
        ['name' => 'review#submit', 'url' => '/api/reviews', 'verb' => 'POST'],
        ['name' => 'review#aggregate', 'url' => '/api/reviews/aggregate', 'verb' => 'GET'],

        // ORGANISATION MERGE (gemeentelijke herindeling / leveranciersovername) —
        // admin-gated (isAdmin guard in the controller body, no-admin-idor safe).
        // @spec openspec/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard
        ['name' => 'merge#dryRun', 'url' => '/api/organisaties/{uuid}/merge/dry-run', 'verb' => 'POST'],
        ['name' => 'merge#execute', 'url' => '/api/organisaties/{uuid}/merge', 'verb' => 'POST'],

        // SELF-SERVICE COLLEAGUE ACCESS (multi-org-membership) — beheerder-of-this-
        // organisation gated (authorizeBeheerder() guard in the controller body,
        // no-admin-idor safe). Delegates the actual membership mutation to
        // OpenRegister's OrganisationService::joinOrganisation()/leaveOrganisation().
        // @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
        ['name' => 'organisationMembers#grant', 'url' => '/api/organisations/{uuid}/members', 'verb' => 'POST'],
        ['name' => 'organisationMembers#revoke', 'url' => '/api/organisations/{uuid}/members/{userId}', 'verb' => 'DELETE'],

        // FEDERATION SETTINGS / MANUAL PULL — admin-gated (AuthorizedAdminSetting).
        ['name' => 'federation#status', 'url' => '/api/federation/status', 'verb' => 'GET'],
        ['name' => 'federation#addPeer', 'url' => '/api/federation/peers', 'verb' => 'POST'],
        ['name' => 'federation#removePeer', 'url' => '/api/federation/peers', 'verb' => 'DELETE'],
        ['name' => 'federation#pull', 'url' => '/api/federation/pull', 'verb' => 'POST'],

        // ========================================================================
        // AANGEBODEN GEBRUIK API ENDPOINTS - Custom Objects API for Gebruiks (Legacy)
        // ========================================================================

        // AangebodenGebruik API endpoints for filtering gebruiks by organization involvement
        ['name' => 'aangebodenGebruik#getGebruiksWhereAfnemer', 'url' => '/api/aangeboden-gebruik/afnemer', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#getAllGebruiksForAmbtenaar', 'url' => '/api/aangeboden-gebruik/ambtenaar', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#getSingleGebruikForAmbtenaar', 'url' => '/api/aangeboden-gebruik/ambtenaar/{gebruikId}', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#getGebruiksWhereDeelnemers', 'url' => '/api/aangeboden-gebruik/deelnemers', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#setGebruikSelfToActiveOrg', 'url' => '/api/aangeboden-gebruik/{gebruikId}/set-self', 'verb' => 'PUT'],
        ['name' => 'aangebodenGebruik#deleteGebruikAsAfnemer', 'url' => '/api/aangeboden-gebruik/{gebruikId}/deny', 'verb' => 'DELETE'],
        ['name' => 'aangebodenGebruik#getApiDocumentation', 'url' => '/api/aangeboden-gebruik/docs', 'verb' => 'GET'],

        // ========================================================================
        // KOPPELINGEN-GEBRUIK API ENDPOINT - UUID-Specific Access for Gebruiks and Koppelingen
        // ========================================================================

        // Koppelingen-Gebruik API endpoint for UUID-specific access to gebruiks and koppelingen
        // Supports filtering by organisation UUID, module UUID, or application/product UUID
        ['name' => 'aangebodenGebruik#getKoppelingenGebruikByUuid', 'url' => '/api/koppelingen-gebruik/{uuid}', 'verb' => 'GET'],

        // ========================================================================
        // MODULE COMPLIANCE MANAGEMENT API ENDPOINTS
        // ========================================================================

        // Bulk sync module standards from compliance objects
        ['name' => 'settings#bulkSyncStandards', 'url' => '/api/bulk-sync-standards', 'verb' => 'POST'],

        // ========================================================================
        // CRONJOB CONFIGURATION API ENDPOINTS
        // ========================================================================
        
        // Cronjob configuration management
        ['name' => 'settings#getCronjobConfig', 'url' => '/api/settings/cronjobs', 'verb' => 'GET'],
        ['name' => 'settings#updateCronjobConfig', 'url' => '/api/settings/cronjobs', 'verb' => 'POST'],
        ['name' => 'settings#getCronjobUsers', 'url' => '/api/settings/cronjobs/users', 'verb' => 'GET'],
        ['name' => 'settings#getCronjobOrganisations', 'url' => '/api/settings/cronjobs/organisations', 'verb' => 'GET'],

        // ========================================================================
        // EOL FEED SYNC API ENDPOINTS (eol-feed-integration)
        // ========================================================================

        ['name' => 'settings#getEolSyncConfig', 'url' => '/api/eol-sync/config', 'verb' => 'GET'],
        ['name' => 'settings#updateEolSyncConfig', 'url' => '/api/eol-sync/config', 'verb' => 'POST'],
        ['name' => 'settings#triggerEolSync', 'url' => '/api/eol-sync/trigger', 'verb' => 'POST'],
        ['name' => 'settings#getEolSyncStatus', 'url' => '/api/eol-sync/status', 'verb' => 'GET'],

        // Gebruik by group
        ['name' => 'gebruik#getGebruiken', 'url' => '/api/gebruik', 'verb' => 'GET'],
        ['name' => 'gebruik#getGebruikenForDeelnemer', 'url' => '/api/gebruik/deelnemer', 'verb' => 'GET'],

        // Portfolio rationalization report (TIME quadrants + EOL + cloud + cost), JSON or CSV (?format=csv).
        // @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
        ['name' => 'portfolioReport#index', 'url' => '/api/portfolio-report', 'verb' => 'GET'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode routing)
        // `postfix` keeps this SPA catch-all from colliding with the bare-root
        // `dashboard#page` route above: both entries target the same
        // controller#method, so without a postfix they generate the same
        // internal route name and the later one silently displaces the first —
        // which 404'd the app's own entry point (`/apps/stackiq/`) for
        // every user, because this route's `path` requirement ('.+') can never
        // match an empty path.
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => ''], 'postfix' => 'spa'],
    ],
];
