# Stackiq uses the shared organisation

## Why

`organization` was the fleet's most persistent slug collision: stackiq and
opencatalogi both declared one, and a schema slug is global per organisation, so
`SchemaMapper::find()` returned whichever row it reached first.

opencatalogi moved onto OpenRegister's `nc-organisation` projection
(opencatalogi#1411). This is the first half of the same move for stackiq: the
resolution changes now, the descriptor stays until the migration below lands.

## The measurement, corrected

The first read of this said stackiq's `organization` was "thinner than it
looks": nothing `$ref`s it, zero rows on the dev instance, no config pointing at
it. That was measured from the descriptors and the config, and it was wrong
about the app.

Removing the schema and running the suite inside a Nextcloud tree is what said
so. **782 tests green before, 9 failures and 1 error after**, and none of them
were about the descriptor:

- `ManifestRegisterSentinelTest` — `src/manifest.json` reads schema
  `organization` from the stackiq register on four pages, including an
  organisation detail page with contact-person sub-lists and a stats block.
- `PublishGateRbacTest` (3 tests) — `organization` carries the public
  `publicationDate` gate every publishable schema must have.
- `SchemaRbacTest` (3 tests) — it carries the `gebruik-beheerder` read grant and
  the organisation-scoped public grants.
- `SbomRegisterShapeTest` — it is asserted NOT to carry SBOM provenance, which
  needs it to exist.
- `RenameDutchCatalogValuesTest` — the `Draft` catalog value is declared on it
  and written by a migration.
- `OpenDataRegisterShapeTest` — it carries the moderation field.

Plus, unmeasured by any test: `OrganizationContactSyncJob`, `ModerationService`,
`ArchiMateExportService`, `OrganizationSyncService`, `StackiqContactSyncService`
and six controllers read it. Around 30 files.

## What this change does

The resolution, and only the resolution. `getSchemaIdForObjectType('organization')`
falls back to `nc-organisation` when nothing local answered.

`nc-organisation` resolves **last**, after this app's own lookups. Putting it
first was the obvious shape and the wrong one: the projection always exists once
OpenRegister ships it, so it would win on an instance that has not yet run the
adopt command, and that instance's rows still live in the local schema. Every
organisation picker would come back empty with nothing reporting why.

Resolving last needs no operator timing. An un-migrated instance keeps resolving
locally; a fresh install has no local schema and lands on the projection; and a
migrated one lands there too, once `prune-retired` has removed the local schema.

## What this change does NOT do

The descriptor keeps `organization`, so **the slug still has two owners** and the
collision is not cleared. Retiring it is a real migration, not a deletion, and
it needs two things this change does not have:

1. **A split.** 11 of the 21 properties map onto OpenRegister's Organisation
   (`name`, `description`, `summary`, `oin`, `tooi`, `rsin`, `pki`, `image`,
   `type`, `registrationStatus`, and `kvk` which stackiq does not carry). The
   other 10 do not: `contactsUid`, `contactpersonen`, `deelnames`,
   `participants`, `samenwerkingtype`, `status`, `publicationDate`,
   `depublicationDate`, `mergedInto`, `registeredBy`, `xml`. Those need a
   stackiq schema of their own that `$ref`s the projection, not deletion.

2. **A writable projection.** `OrganisationObjectSourceProvider` is read-only by
   design, and stackiq's walkthrough tells the user to create an organisation
   with a New button (`advanceOn: object-created`, schema `organization`).
   Migrating onto a read-only projection would retire a working flow. The
   decision taken is to make the projection writable, so any app can register an
   organisation through the object API; that is an OpenRegister change and it
   comes first.

Until both land, the fallback above is inert on any instance that has the local
schema, which is every existing one. It is the path, declared and tested, not
the arrival.

## Frontend

Untouched. The six `getCollection('organization')` sites resolve through
`getSchemaIdForObjectType`, so repointing that moves them.
