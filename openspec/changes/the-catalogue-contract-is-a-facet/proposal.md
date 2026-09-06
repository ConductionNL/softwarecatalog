# The catalogue contract is a facet, not a third contract

## Why

`contract` was claimed by three apps: shillinq, pipelinq and this one. A schema
slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so whichever row the lookup reached first answered for all three.

All three carry `contractNumber`. That is one contract seen from billing, from
sales and from the catalogue, not three contracts. shillinq owns the lifecycle
(ADR-066); pipelinq's side became `salesContract`, and this is the catalogue
side.

This app has its own history with the same mistake. `RenameDutchSchemaSlugs`
renamed `dienst` to `service`, and `service` is itself claimed by three apps.
That collision is still open.

## What changes

The slug becomes `catalogContract` and the schema gains a `contract` uuid
pointing at shillinq's `Contract`. A plain uuid and not a `$ref`, because
shillinq's register is a different register and ADR-062 rule 7 gives a
cross-register target a plain string.

In this app the object type IS the slug: it names the register list, the table
configuration, the modal type list, the settings type lists and the
type-to-config-key map. All of them move together.

The config key stays `contract_schema`, pinned through
`SettingsService::LEGACY_SCHEMA_KEY`. Without that entry the default
`<type>_schema` rule would look for `catalogContract_schema` and resolve
nothing, which is what the catalogue-type resolution test caught.

## One decoy

`ContractApprovalService::DECISION_TYPE_APPROVAL` stays `contract`. It matches
decidiq's `Decision.decisionType` enum value and is not a schema slug.

`SELF_ORGANISATION_RELATION_TYPES` looked like a second decoy and is not: its
entries are passed as `objectType` to `repointBySelfOrganisation()`, so they are
slugs. The merge tests caught that.
