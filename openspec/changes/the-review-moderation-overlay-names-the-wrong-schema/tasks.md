# Tasks

## 1. The fix

- [x] 1.1 Rekey `register.d/catalog-ratings.json` from `assessment` to
      `software-review`, the slug `ReviewService` and `ModerationService` both
      name.
- [x] 1.2 No version bump needed: `computeConfigVersion()` folds each fragment's
      md5 into the import version, so a fragment edit cannot be gated off as
      already-imported.

## 2. The assertions that were missing

- [x] 2.1 Every `register.d` fragment overlays a schema the register declares.
      Generic, so the next fragment keyed on a title fails immediately.
- [x] 2.2 The MERGED register gates the review schema's public read on
      `status: approved`, with no bare `"public"` surviving. Keyed off
      `ReviewService::REVIEW_TYPE`, so a later slug move breaks the test instead
      of quietly un-gating the schema again.
- [x] 2.3 A control asserting the BASE register alone is NOT gated. Without it
      the test cannot distinguish "the overlay applied" from "the base was
      already fine", and would keep passing if the fragment stopped being merged.
- [x] 2.4 `DeepMergeAuthorizationTest` rekeyed to `software-review` as well. It
      still proves what it always proved; it just no longer names a schema that
      does not exist.
- [x] 2.5 Verified by reverting the fragment key: exactly 2.1 and 2.2 go red.

## 3. Not covered

- [ ] 3.1 The live import. See the proposal: the descriptor path is tested
      against the real files, but OpenRegister's `ImportHandler` applying the
      authorization change to an already-existing `software-review` row is not
      exercised here. An operator upgrading such an instance should read the
      schema's `authorization.read` back.
- [ ] 3.2 The `assessment` schema row this fragment created on any instance
      where it already imported. It carries two properties and no objects, and
      `occ openregister:schemas:prune-retired` is the tool; it is left alone here
      because removing a row is a data operation, not a descriptor change.
