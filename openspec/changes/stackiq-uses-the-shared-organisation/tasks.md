# Tasks

## 1. Resolution

- [x] 1.1 `getSchemaIdForObjectType('organization')` falls back to
      OpenRegister's `nc-organisation`.
- [x] 1.2 It resolves LAST, after the local lookups, so an un-migrated
      instance's rows stay visible.
- [x] 1.3 Fails to null when OpenRegister is absent or too old to carry the
      projection, rather than resolving organisations to nothing.

## 2. Descriptors

The register descriptors are deliberately unchanged. Removing `organization`
from them was tried and reverted: 782 tests green before, 9 failures and 1 error
after, across the manifest sentinel, the publish gate, the RBAC grants, the SBOM
shape, a catalog-value migration and the moderation field. See the proposal.

- [x] 2.1 Measure what a removal actually breaks, inside a Nextcloud tree.
      `composer check:strict` reports `test:all` SKIPPED in a bare checkout, and
      that skip is what let the first read call the schema unused.

## 3. Frontend

- [x] 3.1 Nothing. The six `getCollection('organization')` sites resolve through
      `getSchemaIdForObjectType`.

## 4. The migration, not in this change

- [ ] 4.1 OpenRegister: make `OrganisationObjectSourceProvider` writable, so an
      app can register an organisation through the object API. Stackiq's
      walkthrough creates one with a New button; a read-only projection retires
      that flow. This blocks everything below.
- [ ] 4.2 Stackiq: a new schema carrying the 10 properties the projection has no
      column for, `$ref`-ing `nc-organisation` for the identity facet.
- [ ] 4.3 Repoint the ~30 reading sites, the four manifest pages, the publish
      gate, the RBAC grants and the contact-sync job.
- [ ] 4.4 `occ openregister:organisations:adopt --register stackiq --apply`,
      then `occ openregister:schemas:prune-retired --app stackiq --slug
      organization --apply`. The descriptor change alone removes nothing:
      `ImportHandler` unions schema ids.

⚠️ Step 4.4's first command reports every property Organisation has no column
for, before it writes. An instance that uses them needs 4.2 landed first — they
are reported precisely so that decision happens before the rows are gone.
