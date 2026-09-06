# The review-moderation overlay names the wrong schema

## What was wrong

`lib/Settings/register.d/catalog-ratings.json` overlays the review schema with
the status-gated public read that REQ-CR-001 requires:

```json
"read": [{"group": "public", "match": {"status": "approved"}}, ...]
```

It keyed that overlay `assessment`. The schema's slug is `software-review`.

`assessment` is the schema's TITLE. `RenameDutchSchemaSlugs` renamed
`beoordeeling` → `software-review`, and its own docblock records why: *"Targets
are read off each schema's own English `title`… `beoordeeling` was already
titled 'Assessment'."* The fragment was written against the title and the slug
moved out from under it.

## What that cost

`softwarecatalogus_register.json` gives `software-review` a base
`read: ["public"]`. The overlay was supposed to replace it — and
`deepMergeConfig()` does replace `authorization` lists correctly, which is
tested. It replaced the read rule on a schema key that nothing else declares.

So `software-review`, which is what `ReviewService::REVIEW_TYPE` and
`ModerationService::MODERATED_TYPE_REVIEW` both name, kept its bare public read.
**Every pending and every rejected review was readable by an unauthenticated
caller**, which is precisely the hole the catalog-ratings change was written to
close, and precisely what REQ-CR-001 says MUST NOT be the case.

## Why nothing caught it

Three things all looked right at once.

`DeepMergeAuthorizationTest` proves the merge replaces rather than concatenates,
and it kept proving that. It builds its own base, keyed `assessment` to match
the fragment, so the one thing it structurally cannot notice is the fragment
naming a schema the register does not have.

The fragment merge unions by key, so an unmatched key is not an error. It
creates a schema. The overlay then applies perfectly, to nothing.

And `assessment` reads like a real schema name. It is one, in learniq — which is
how this surfaced at all: the fleet-wide slug-collision sweep listed
`assessment: learniq, stackiq`, and stackiq's entry turned out to be a
two-property phantom.

## The change

Rekey the fragment to `software-review`, and add the two assertions that would
have caught it:

1. **Every fragment overlays a schema the register declares.** Generic, so the
   next fragment written against a title rather than a slug fails immediately.
2. **The MERGED register gates the review schema**, resolved from
   `ReviewService::REVIEW_TYPE` rather than a literal, with a control asserting
   the base alone is NOT gated. Without that control the test cannot tell "the
   overlay applied" from "the base was already fine".

## What is not proven here

The live import. This instance does not have stackiq's register imported, and
importing ~50 schemas onto the shared e2e rig to prove one authorization rule is
not a trade worth making. The path from fragment to schema is
`deepMergeConfig()`, which the new test now exercises against the real files;
what remains unexercised is OpenRegister's `ImportHandler` applying an
authorization change to an already-existing schema, which `SettingsService`
already forces through its content-derived version signature (the fragment's md5
is folded in, so a fragment edit cannot be gated off as "already imported").

An operator upgrading an instance that already has `software-review` should read
the schema's `authorization.read` back afterwards.
