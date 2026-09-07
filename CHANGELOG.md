# Changelog

## [Unreleased]
### Changed
- EOL feed: the register and schema slugs the feature reads from are corrected.
  `EOL_DEFAULT_REGISTER` still said `openconnector`, a register renamed to
  `integriq` in the fleet rename, so a default configuration had been addressing
  a register that does not answer to that name. Integriq has since renamed the
  two schemas to `eol_product` and `eol_cycle`, so those defaults move too. An
  install whose admin opted in has the old literals stored, where the default
  never applies, and is re-pointed by the new `RepointEolSyncConfig` repair
  step, guarded on the exact stored value so an admin's own register is never
  touched. The failure this fixes is silent: an unresolvable schema reads as
  "this module has no EOL data", so the sync ran, reported success and stamped
  nothing. (eol-feed-integration)
- Legacy quality cleanup: fixed the single surfacing PHPCS alignment error, captured a `phpmd.baseline.xml` and wired `--baseline-file` into the `composer phpmd` gate (matching the fleet pattern) so the unified quality gate runs green while legacy mess is burned down incrementally. Documented the baseline posture for PHPMD and PHPStan in the README.
- i18n: re-authored 158 Dutch schema property `title` values across the `softwarecatalogus_register.json` register to English (property keys unchanged, no API impact). Added matching `l10n/en.json`/`l10n/en.js` and `l10n/nl.json`/`l10n/nl.js` translation keys so Dutch users continue to see Dutch labels via the app's l10n layer instead of the register.

## 0.1.6 – 2025-06-18
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

## 0.1.5 – 2024-09-07
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

### Added
- Initial release

