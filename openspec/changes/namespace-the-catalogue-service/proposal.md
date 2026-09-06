# Namespace the catalogue service

## Why

`service` was claimed by three apps: shillinq, pipelinq and this one. They share
`name` and nothing else. shillinq keeps the bare slug, pipelinq took
`appointmentService`, and this is the catalogue dienst.

This app created its share of the problem. `RenameDutchSchemaSlugs` renames
`dienst` to `service`, and that target was already taken — the same mistake
shillinq made renaming `Mandaat` to `Mandate`. A Dutch-to-English pass that does
not check the destination just moves the collision.

## Why the rename is not in the Dutch map

The obvious fix, pointing `dienst` straight at `catalogService`, does not work
here: `RenameDutchSchemaSlugDecisions` forbids two sources targeting one name,
and an install already on `service` would need `service => catalogService`
alongside it.

Ordering does it instead. `RenameDutchSchemaSlugs` runs first and lands on
`service`; `RenameCollidingSchemaSlugs` then moves it to `catalogService`. That
also keeps the separation the `contract` change established: the Dutch map
translates words, the colliding map ends cross-app collisions.

## What the tests caught that reading did not

Three sites looked like decoys and were not, or the reverse:

- **`$ref: "#/components/schemas/service"`** in ten places across two register
  files. Missing these would have left `catalogContract.service` and
  `connection.service` pointing at a schema that no longer exists.
- **`'via' => 'service'`** in `PortalContributionProvider` is a PROPERTY name on
  `catalogContract`, not a schema. I changed it, the portal test rejected it,
  and it went back.
- A **`required` entry** in `catalogContract` naming the `service` property,
  which a line-shaped regex renamed as if it were a register-list entry.

Genuine decoys that stayed: the Dutch column map, `"service": {}` property
placeholders, a complaint-style subject-type in doc comments, and the
`fields` arrays listing properties.
