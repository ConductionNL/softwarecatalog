<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# End-of-life feed integration

Makes `moduleVersie.datumEindeOndersteuning` (end-of-support date)
data-driven by matching catalog products to
[endoflife.date](https://endoflife.date) product cycles, instead of relying
on manual entry alone. The existing EOL indicators, EOL-approaching filter,
roadmap, and `eol-approaching` notification rule declared in
`application-lifecycle-tracking` are unchanged — this feature only improves
what populates the field they already read.

Specification:
[`openspec/specs/eol-feed-integration/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/eol-feed-integration/spec.md).

## Architecture: stackiq never calls endoflife.date

All fetching of endoflife.date data happens in the sibling **Integriq**
`endoflife-date-source` change — a Source + Synchronization + Mapping that
polls `https://endoflife.date/api` and upserts `eol_product`/`eol_cycle`
OpenRegister objects. Softwarecatalog only *reads* those already-ingested
objects via `ObjectService`; there is no HTTP client, outbound URL
configuration field, or network call to endoflife.date (or any other EOL
feed) anywhere in this app's code. This mirrors the pattern established by
`module-vulnerability-tracking` for CVE enrichment: transport lives in
Integriq, matching and consumption live in the leaf app.

```
Integriq (sibling repo, optional)
  endoflife-date-source: fetches endoflife.date → eol_product/eol_cycle objects
                          │  read-only, via ObjectService — NO HTTP here
                          ▼
stackiq (this feature)
  module.eolProductSlug  ──┐  (mapping config, per product)
                            │
  EolSyncJob (scheduled) ─► EolSyncService ─► EolMatcherService
  "Sync now" (manual)    ─┘                        │
                                                     ▼
                    moduleVersie.datumEindeOndersteuning / eolBron / eolBijgewerktOp
```

## Mapping a product

Each `module` gains an optional **`eolProductSlug`** field — the
endoflife.date product identifier it corresponds to (e.g. `postgresql`,
`nextcloud`). It is edited through the same generic OpenRegister object form
every other module field uses; no dedicated frontend code is needed for the
field itself. Modules without `eolProductSlug` set are never read or written
by the matcher — the mapping is strictly opt-in, per product.

## Conservative matching — unambiguous only

`EolMatcherService` compares a `moduleVersie.versie` string (e.g. `21.3.1`)
against the `cycle` values of the mapped module's `eol_cycle` rows, using
dot-segment version-prefix matching, most-specific level first:

- `21.3.1` against cycles `21.3` and `21` → matches `21.3` (deeper prefix
  wins).
- `2` against cycles `2.0` and `2.1` → **ambiguous tie**, skipped — the
  matcher never guesses.
- No cycle shares any leading segment → **no match**, skipped.

A stamp is only ever written on an **exactly-one-candidate** result at the
most-specific matching depth. Ties and no-matches leave the `moduleVersie`
completely untouched — it remains exactly as available for manual
`datumEindeOndersteuning` entry as it was before this feature existed.

## Stamping preserves every other field

When a match is found, the matcher reads the *complete* current
`moduleVersie` object, sets three fields on the in-memory copy —
`datumEindeOndersteuning` (from the matched cycle's `eol` date), `eolBron`
(provenance source, `endoflife.date`), and `eolBijgewerktOp` (the sync run's
timestamp) — and saves the full object back. OpenRegister's `saveObject()`
is PUT-semantic (omitted properties are nulled, not left alone), so every
other field (`versie`, `status`, `gebruiken`, `beschrijvingKort`, ...)
carries forward unchanged. A hand-entered `datumEindeOndersteuning` never
gains `eolBron`/`eolBijgewerktOp` — those two fields are only ever written
by the matcher, so their presence reliably distinguishes a feed-sourced date
from a manually entered one.

## Schedule and manual trigger

`EolSyncJob` (a Nextcloud `TimedJob`, system/non-RBAC context) re-runs the
matcher on a configurable interval (default 24h, floored at 5 minutes). An
admin can also trigger the identical logic immediately via **Sync now** in
Settings → Software Catalog → *End-of-life feed sync* — both paths call the
same `EolSyncService::run()`, so they can never drift apart.

## Graceful degradation

If the configured register/schema cannot be resolved — Integriq's
`endoflife-date-source` change is not installed, the register/schema names
are wrong, or the feature is simply disabled — `EolSyncService` returns a
status of `available: false` with a `reason` code, and neither trigger path
raises an error. Manual `datumEindeOndersteuning` entry, the EOL-approaching
filter, the roadmap, and the notification rule all continue to work exactly
as they do today; none of them require this feature to be configured.

Reason codes surfaced in the settings status panel:

| Reason                              | Meaning                                                        |
|--------------------------------------|-----------------------------------------------------------------|
| `disabled`                           | The feature toggle is off.                                      |
| `openregister-not-installed`         | OpenRegister itself is not installed.                            |
| `object-service-unavailable`         | OpenRegister's `ObjectService` could not be resolved.            |
| `module-schema-not-configured`       | Softwarecatalog's own `module`/`moduleVersie` schema isn't set up yet. |
| `eol-register-or-schema-not-found`   | The configured EOL register/schema names don't resolve — is `endoflife-date-source` installed? |
| `not-yet-run`                        | No sync has ever run.                                            |

## Settings

**Settings → Software Catalog → End-of-life feed sync**:

- **Enable EOL feed sync** — off by default; the matcher never reads or
  writes anything while disabled.
- **Register slug** / **eol_product schema slug** / **eol_cycle schema slug**
  — pre-filled with the names the Integriq `endoflife-date-source`
  change provisions (`integriq` / `eol_product` / `eol_cycle`). Editable
  without a code change, since Integriq and stackiq are
  separate release trains and the provisioned names could differ.
- **Sync interval (minutes)** — how often the scheduled job re-runs
  (minimum enforced: 5 minutes).
- **Sync now** — runs the same match/stamp logic immediately.
- A status banner reports the last run's matched/skipped counts and
  timestamp, or the unavailability reason when the feed can't be reached.

## API

```
GET  /apps/stackiq/api/eol-sync/config    — current configuration
POST /apps/stackiq/api/eol-sync/config    — update configuration
POST /apps/stackiq/api/eol-sync/trigger   — run a sync now, returns status
GET  /apps/stackiq/api/eol-sync/status     — last-recorded status
```

All four endpoints require Nextcloud admin-group authorization (the default
posture of `SettingsController` methods — no `#[NoAdminRequired]`), the same
pattern as every other settings-admin-controller endpoint.
