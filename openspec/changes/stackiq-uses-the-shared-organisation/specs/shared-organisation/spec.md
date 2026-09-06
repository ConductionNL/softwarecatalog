# Shared organisation

## ADDED Requirements

### Requirement: The organisation is OpenRegister's (REQ-SSO-101)

An organisation lookup MUST resolve to OpenRegister's `nc-organisation`
projection when this app has no local organisation schema of its own.

It MUST resolve LOCALLY FIRST where a local schema exists. The projection always
exists once OpenRegister ships it, so preferring it would win on an instance
that has not yet migrated its rows, and those rows would vanish from every
picker with nothing reporting why.

Resolution MUST fail to null when OpenRegister is absent or carries no
projection, so the local schema still answers.

#### Scenario: A fresh install resolves to the projection

- **GIVEN** an instance with no local organisation schema
- **WHEN** an organisation lookup runs
- **THEN** it resolves to `nc-organisation`.

#### Scenario: An un-migrated instance keeps its own rows

- **GIVEN** an instance whose local organisation schema still holds rows
- **WHEN** an organisation lookup runs
- **THEN** it resolves to the local schema, not the projection.

#### Scenario: A missing projection does not blank the lookup

- **GIVEN** an OpenRegister with no `nc-organisation`
- **WHEN** an organisation lookup runs
- **THEN** it falls through to the local schema rather than resolving to nothing.
