# Tasks

- [x] 1.1 Move the key, slug, register-list entry and table config, and add the shillinq pointer
      **files**: lib/Settings/softwarecatalogus_register.json, lib/Settings/register.d/contracts-to-decidesk.json, lib/Settings/stackiq_mock_register.json
- [x] 1.2 Follow the slug in the manifest
      **files**: src/manifest.json
- [x] 2.1 Follow the object type through the services and portal provider
      **files**: lib/Service/ContractApprovalService.php, lib/Service/ContractStatusService.php, lib/Service/MergeOrganisatieService.php, lib/Portal/PortalContributionProvider.php, lib/Repair/BackfillContractApprovalState.php
- [x] 2.2 Follow it through the settings type lists and pin the legacy config key
      **files**: lib/Service/SettingsService.php
- [x] 2.3 Follow it in the frontend type lists
      **files**: src/modals/Modals.vue, src/views/LicensePostureView.vue, src/components/contracts/ContractApprovalPanel.vue
- [x] 3.1 Rename the row in place before the import, scoped to this app
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, appinfo/info.xml
- [x] 4.1 Follow the slug in the e2e seed list, which exits before Playwright when it misses
      **files**: tests/e2e/ci-seed.sh
- [x] 4.2 Repoint the test stubs keyed on the old slug
      **files**: tests/Unit/Service/MergeOrganisatieServiceTest.php, tests/Unit/Service/SettingsServiceCatalogTypeResolutionTest.php, tests/Unit/Settings/ContractRbacTest.php, tests/Unit/Controller/FacetControllerTest.php, tests/Unit/Service/FacetServiceTest.php
