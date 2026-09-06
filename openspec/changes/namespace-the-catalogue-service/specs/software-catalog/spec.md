# Software catalog

## MODIFIED Requirements

### Requirement: The catalogue service is namespaced (REQ-SC-061)

The catalogue dienst schema's slug SHALL be `catalogService` and SHALL NOT be
`service`. shillinq keeps the bare slug; pipelinq uses `appointmentService`.

The rename SHALL be carried by `RenameCollidingSchemaSlugs` and SHALL NOT be
folded into `RenameDutchSchemaSlugs`. That map's planner forbids two sources
targeting one name, and both `dienst` and `service` would have to point at
`catalogService`. The Dutch pass SHALL run first and land on `service`; the
colliding pass SHALL then move it.

Every `$ref` targeting the schema SHALL follow the rename. A `$ref` left on the
old name points at a schema that no longer exists.

The rename SHALL NOT touch `service` where it is a property name, including the
`via` key in the portal contribution provider and the `required` entry on
`catalogContract`.

#### Scenario: The Dutch pass and the colliding pass compose

- **GIVEN** an install carrying `dienst`
- **WHEN** the repair steps run in order
- **THEN** the row ends on `catalogService`, keeping its schema id throughout.

#### Scenario: An install already on the colliding slug is moved

- **GIVEN** an install carrying `service` under this app
- **WHEN** the colliding pass runs
- **THEN** the row is renamed to `catalogService`.

#### Scenario: The via property is still a property

- **WHEN** the vendor contract collection is read
- **THEN** its `via` is `service`, the property on `catalogContract`, and that
  property's `$ref` resolves to `catalogService`.
