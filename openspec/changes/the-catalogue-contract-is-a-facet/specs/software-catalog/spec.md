# Software catalog

## MODIFIED Requirements

### Requirement: The catalogue contract points at the shillinq contract (REQ-SC-060)

The catalogue contract schema's slug SHALL be `catalogContract` and SHALL NOT be
`contract`.

Three apps declared a `contract` and all three carry `contractNumber`, so they
describe one contract from three sides. shillinq owns the lifecycle (ADR-066).

The schema SHALL carry a `contract` property holding the UUID of the shillinq
`Contract`. It SHALL be a plain uuid string and SHALL NOT be a `$ref`, because
shillinq's register is a different register and ADR-062 rule 7 gives a
cross-register target no `$ref`.

The object type SHALL move with the slug everywhere it is named: the register's
schema list, the register table configuration, the generic modal type list, the
settings type lists, and `tests/e2e/ci-seed.sh`. The seed list is checked after
the import and exits before Playwright, so a missed slug there reports every
spec as not run rather than as a failure.

The config key SHALL remain `contract_schema`, pinned through
`SettingsService::LEGACY_SCHEMA_KEY`. The default `<type>_schema` rule would
otherwise look for `catalogContract_schema` and resolve nothing.

`ContractApprovalService::DECISION_TYPE_APPROVAL` SHALL remain `contract`. It is
decidiq's `Decision.decisionType` enum value, not a schema slug.

#### Scenario: Every catalogue type still resolves

- **WHEN** each catalogue object type is resolved to a schema and a register
- **THEN** `catalogContract` resolves to both, through the pinned legacy key.

#### Scenario: The catalogue facet points at its owner

- **WHEN** the register JSON is read
- **THEN** `catalogContract` carries a `contract` property targeting shillinq.
