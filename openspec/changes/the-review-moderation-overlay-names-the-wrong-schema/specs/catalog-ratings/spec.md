# Catalog ratings

## ADDED Requirements

### Requirement: A register fragment MUST overlay a schema the register declares (REQ-CR-020)

Every schema key in a `lib/Settings/register.d/*.json` fragment MUST already
exist in `softwarecatalogus_register.json`.

A fragment is an OVERLAY and the merge unions by key, so a fragment naming a
schema the base does not declare does not fail. It quietly creates one, and the
overlay then applies perfectly, to nothing. There is no error, no warning and no
failing test, because a schema nobody declares is also a schema nobody reads.

This is not hypothetical. `catalog-ratings.json` keyed its overlay `assessment`,
which is the schema's TITLE; its slug is `software-review`
(`beoordeeling` → `software-review`, `RenameDutchSchemaSlugs`). The
status-gated public read it exists to install therefore never reached the
schema holding the reviews, which kept the base `read: ["public"]` that
REQ-CR-001 forbids. Every pending and rejected review was publicly readable,
which is the exact hole this capability was written to close.

#### Scenario: No fragment overlays a schema the register does not declare

- **WHEN** every `register.d` fragment's schema keys are compared against the
  register's declared schemas
- **THEN** every key is present in the register.

### Requirement: The gated read MUST be asserted on the merged register, not on a synthetic base (REQ-CR-021)

The test for REQ-CR-001 MUST merge the REAL fragments onto the REAL register
JSON and assert on the schema the review services name, resolved from their own
constants rather than from a literal.

A test that builds its own base keyed to match the fragment proves the merge
function and nothing else. `DeepMergeAuthorizationTest` did exactly that, and
passed throughout the period the overlay was landing on a schema that did not
exist.

The test MUST also assert that the BASE register alone is NOT gated. Without
that control it cannot distinguish "the overlay applied" from "the base was
already correct", and it would keep passing if the fragment stopped being
merged at all.

#### Scenario: The merged register gates the review schema

- **GIVEN** the register JSON with every `register.d` fragment merged over it
- **WHEN** the `authorization.read` of the schema named by
  `ReviewService::REVIEW_TYPE` is inspected
- **THEN** it contains `{"group": "public", "match": {"status": "approved"}}`
- **AND** it does not contain the bare string `"public"`.

#### Scenario: The base register on its own is not gated

- **WHEN** the register JSON is inspected WITHOUT its fragments
- **THEN** the review schema's `authorization.read` contains the bare
  `"public"` that the fragment removes.
