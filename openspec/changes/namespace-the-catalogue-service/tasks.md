# Tasks

- [x] 1.1 Move the schema key, slug and register-list entry structurally, leaving properties named `service` alone
      **files**: lib/Settings/softwarecatalogus_register.json, lib/Settings/stackiq_mock_register.json
- [x] 1.2 Follow every `$ref` targeting the schema
      **files**: lib/Settings/softwarecatalogus_register.json, lib/Settings/stackiq_mock_register.json
- [x] 1.3 Follow the slug in the manifests
      **files**: src/manifest.json
- [x] 2.1 Follow the object type through the facet, publication and review services
      **files**: lib/Service/FacetService.php, lib/Service/PublicationService.php, lib/Service/ReviewService.php, lib/Service/ReviewAggregateService.php, lib/Service/AanbodService.php, lib/Controller/FacetController.php, lib/Portal/PortalContributionProvider.php
- [x] 2.2 Follow it through the settings type lists and the legacy config-key map
      **files**: lib/Service/SettingsService.php
- [x] 2.3 Follow it in the frontend type lists
      **files**: src/modals/Modals.vue, src/views/FacetedCatalogIndexView.vue, src/store/modules/facets.spec.js, src/services/facets.spec.js
- [x] 3.1 Carry the rename in the colliding map, ordered after the Dutch pass
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, appinfo/info.xml
- [x] 4.1 Repoint the test stubs, leaving the `via` property assertion alone
      **files**: tests/Unit/
