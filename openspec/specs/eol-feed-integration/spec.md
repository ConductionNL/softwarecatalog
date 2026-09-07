# eol-feed-integration Specification

## Purpose
TBD - created by archiving change eol-feed-integration. Update Purpose after archive.
## Requirements
### Requirement: Products are mapped to endoflife.date via per-module config

Each `module` SHALL gain an optional `eolProductSlug` field identifying its
corresponding endoflife.date product identifier. The EOL matcher SHALL only
process a module when `eolProductSlug` is set; modules without it SHALL be
left entirely alone (no read, no write). The register and schema names used
to read `eol_product`/`eol_cycle` data SHALL be configurable in settings,
defaulting to the names the Integriq `endoflife-date-source` change
provisions.

#### Scenario: A mapped module is eligible for matching

- **WHEN** an admin sets `eolProductSlug` on a `module` (e.g. `postgresql`)
- **THEN** the EOL matcher includes that module in its next run
- **AND** modules with no `eolProductSlug` set are skipped without any read
  or write against them

#### Scenario: Register and schema names are configurable, not hardcoded

- **WHEN** an admin opens the EOL sync settings panel
- **THEN** the register slug and the `eol_product`/`eol_cycle` schema slugs are
  editable fields, pre-filled with the defaults matching the Integriq
  `endoflife-date-source` change's provisioned names
- **AND** changing them takes effect on the next sync without a code change

#### Scenario: a stored configuration survives a rename on the provisioning side

- **GIVEN** an admin saved the EOL sync configuration before Integriq's register
  was renamed from `openconnector` to `integriq` and its two schemas from
  `eolProduct`/`eolCycle` to `eol_product`/`eol_cycle`
- **WHEN** the app is upgraded
- **THEN** the stored `register`, `productSchema` and `cycleSchema` values are
  re-pointed at the current slugs
- **AND** a configuration naming any other register or schema is left exactly as
  the admin wrote it, because the guard is the exact stored value and not the
  field name
- **AND** a second upgrade changes nothing
- @e2e exclude a repair step runs at `occ upgrade` and nowhere else, so no browser session can reach it. The decision table, including the leave-alone cases, is covered by `tests/Unit/Repair/RepointEolSyncConfigTest.php`.

### Requirement: Version matching is conservative and unambiguous only

The matcher SHALL compare a `moduleVersie.versie` string against the `cycle`
values of the mapped module's `eol_cycle` rows using version-prefix matching
(most-specific level first) and SHALL stamp a value **only** when exactly one
cycle matches at the most-specific level. When zero cycles match, or more
than one cycle matches at the same most-specific level (an ambiguous tie),
the matcher SHALL skip that `moduleVersie` and leave its existing fields
untouched.

#### Scenario: Unambiguous match stamps the version

- **WHEN** a `moduleVersie` with `versie` `21.3.1` is matched against
  `eol_cycle` rows containing exactly one cycle `21.3` for the mapped product
- **THEN** that `moduleVersie` is stamped from the `21.3` cycle's `eol` date

#### Scenario: Ambiguous match is skipped, not guessed

- **WHEN** a `moduleVersie` with `versie` `2` matches two candidate cycles
  (`2.0` and `2.1`) at the same most-specific level with no single
  most-specific winner
- **THEN** the matcher does not stamp that `moduleVersie`
- **AND** its existing `datumEindeOndersteuning` (or absence thereof) is
  unchanged

#### Scenario: No match leaves the version untouched

- **WHEN** a `moduleVersie`'s `versie` matches no cycle for the mapped
  product
- **THEN** the matcher does not stamp that `moduleVersie`
- **AND** the version remains available for manual `datumEindeOndersteuning`
  entry exactly as before this feature existed

### Requirement: Stamping preserves every other field and records provenance

When the matcher stamps a `moduleVersie`, it SHALL read the complete current
object, set `datumEindeOndersteuning` from the matched cycle's `eol` date
together with `eolBron` (source identifier, e.g. `endoflife.date`) and
`eolBijgewerktOp` (the sync run's timestamp), and save the complete object —
every other existing field on that `moduleVersie` (including but not limited
to `versie`, `status`, `gebruiken`) SHALL be carried forward unchanged, per
OpenRegister's PUT-semantic `saveObject`.

#### Scenario: An unrelated field survives a stamp

- **WHEN** a `moduleVersie` with an existing `beschrijvingKort` value is
  matched and stamped
- **THEN** the saved object's `datumEindeOndersteuning`, `eolBron`, and
  `eolBijgewerktOp` reflect the match
- **AND** `beschrijvingKort` and every other previously-set field are
  unchanged on the saved object

#### Scenario: Provenance distinguishes feed-sourced dates from manual entry

- **WHEN** a user views a `moduleVersie` whose `datumEindeOndersteuning` was
  set by the matcher
- **THEN** `eolBron` and `eolBijgewerktOp` are present and identify the value
  as feed-sourced
- **AND** a `moduleVersie` whose `datumEindeOndersteuning` was entered by
  hand has no `eolBron`/`eolBijgewerktOp` set

### Requirement: EOL sync runs on a schedule with a manual trigger

The matcher SHALL run as a Nextcloud background job on a configurable
interval, operating in system (non-RBAC) context per the `cronjob-context`
pattern, and SHALL also be runnable on demand via a manual "sync now"
endpoint on the settings admin controller. Both trigger paths SHALL invoke
the same underlying sync/match logic.

#### Scenario: The scheduled job runs the match

- **WHEN** the EOL background job's configured interval elapses
- **THEN** it runs the matcher across all modules with `eolProductSlug` set
- **AND** it records a status summary (matched count, skipped count,
  last-run timestamp)

#### Scenario: An admin triggers a sync manually

- **WHEN** an admin calls the manual EOL sync trigger from settings
- **THEN** the same match logic runs immediately, outside the scheduled
  interval
- **AND** the resulting status is returned and reflected in the settings
  status view

### Requirement: The feature degrades gracefully when the feed is unavailable

The matcher SHALL make no changes and SHALL NOT raise an error to the end
user when the configured EOL register or schema cannot be resolved
(Integriq not installed, register/schema missing, or the sync is
disabled in settings). The settings status SHALL report the feed as
unavailable with a reason, distinct from "configured but zero matches yet".
Manual entry of `datumEindeOndersteuning`, the EOL-approaching filter, the
roadmap, and the `eol-approaching` notification rule (all declared in
`application-lifecycle-tracking`) SHALL continue to function fully
regardless of feed availability.

#### Scenario: Missing register degrades to manual-only, not an error

- **WHEN** the EOL sync runs (scheduled or manual) and the configured
  register/schema cannot be found
- **THEN** no `moduleVersie` is modified and no error is surfaced to the user
- **AND** the settings status shows the feed as unavailable with a reason

#### Scenario: Core lifecycle capability is unaffected by feed absence

- **WHEN** the Integriq `endoflife-date-source` change is not installed
- **THEN** users can still enter `datumEindeOndersteuning` manually, the
  EOL-approaching filter and roadmap still work, and the
  `eol-approaching` notification rule still evaluates existing dates

### Requirement: Softwarecatalog performs no direct HTTP to the EOL feed

All fetching of endoflife.date data SHALL happen in the Integriq
`endoflife-date-source` source/synchronization; stackiq SHALL only
read already-ingested `eol_product`/`eol_cycle` objects via OpenRegister's
`ObjectService`/`ConfigurationService`. No HTTP client, URL configuration
field, or outbound network call to endoflife.date (or any other EOL feed)
SHALL exist in stackiq code.

#### Scenario: The matcher's data source is OpenRegister, not HTTP

- **WHEN** the EOL matcher is inspected
- **THEN** its data access is limited to `ObjectService`/`ConfigurationService`
  calls against the configured register/schema
- **AND** no HTTP client or outbound URL to endoflife.date exists anywhere in
  stackiq's codebase

