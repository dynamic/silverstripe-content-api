# Error codes

Every error response carries a stable, machine-readable code — a caller (human or agent) should
branch on `error.code`, never on `error.message` text. Source:
[`ErrorCode.php`](../../src/Errors/ErrorCode.php).

```json
{ "data": null, "meta": {}, "error": { "code": "VALIDATION_FAILED", "status": 422,
  "message": "...", "details": [ { "field": "Title", "code": "UNKNOWN_FIELD", "message": "..." } ] } }
```

`details` is present on multi-field failures (write validation, batch atomic rollback),
`UNKNOWN_CLASS` when the module can point at a likely cause, `ENV_FORBIDDEN`, and absent
otherwise.

| Code | HTTP | Triggered by |
|---|---|---|
| `UNAUTHENTICATED` | 401 | No token header, or `getOwner()` couldn't resolve a member from it |
| `TOKEN_EXPIRED` | 401 | Token past its strict `ApiTokenExpire` |
| `FORBIDDEN` | 403 | Member lacks `CONTENT_API_ACCESS` or `CONTENT_API_POPULATE` |
| `FORBIDDEN_CLASS` | 403 | Class doesn't expose the requested verb via `api_access`/`content_api_access` |
| `FORBIDDEN_RECORD` | 403 | The model's own `canView`/`canEdit`/`canDelete`/`canCreate` denies |
| `ENV_FORBIDDEN` | 403 | Population endpoint called outside `population_enabled_environments` without the override. `details[0]` carries `environment`, `envVar`, and `populationEnabledEnvironments` so a caller can distinguish this from an ACL failure and act on it programmatically (#126) |
| `UNKNOWN_CLASS` | 404 | Class ref not in the merged models map, or its mapped FQCN doesn't exist. `details[0].code` distinguishes `CLASS_NOT_MAPPED` (a real, registerable class exists with that name but has no `models:` entry — `details[0].message` names the FQCN), `CLASS_ALREADY_MAPPED` (that class is already registered, under a different ref — `details[0].message` names the existing ref, use that instead of adding a duplicate), and `CLASS_NOT_FOUND` (a `models:` entry exists but the FQCN it points at doesn't autoload), when the module can tell which. Never suggests a class the module hardcodes as never-exposable (`Member`/`Group`/`Permission`/etc.) regardless of project config |
| `NOT_FOUND` | 404 | Record / external id / related record not found |
| `METHOD_NOT_ALLOWED` | 405 | Wrong HTTP verb for the route |
| `MULTIPLE_MATCHES` | 409 | An external id (or a `urlSegment` page match) resolves to more than one record |
| `ALREADY_EXISTS` | 409 | A create conflicts with an existing record |
| `ASSET_CONFLICT` | 409 | Asset path conflict not resolvable by the chosen `conflict` mode |
| `UNPUBLISH_STRANDS_DESCENDANTS` | 409 | `unpublish` or `archive` (stage action, or batch `delete` with `mode: "unpublish"`/`"archive"`) would cascade-delete `Hierarchy` descendants currently nested under the record (`SiteTree.enforce_strict_hierarchy`, the framework default) — pass `force: true` to bypass and accept the loss (#71) |
| `VALIDATION_FAILED` | 422 | A model's own `ValidationException` on write (mapped to structured `details`, never a raw exception message) |
| `UNKNOWN_FIELD` | 422 | Payload key isn't a recognized db field/relation, under `api_unknown_fields: strict` |
| `READONLY_FIELD` | 422 | Field/relation isn't writable per the guarded/allowlist policy, is protected, or is a bare polymorphic `{Name}Class` column set directly |
| `INVALID_VALUE` | 422 | A value doesn't fit its column's own constraints — currently: an Enum/MultiEnum field given a value outside its declared list |
| `UNKNOWN_RELATION` | 422 | has_many/many_many relation name doesn't exist on the class |
| `UNRESOLVED_REF` | 422 | A composition `$ref` alias never resolves |
| `CIRCULAR_REF` | 422 | A cycle among only-deferred composition elements (mutual unresolved `$ref`s) |
| `EXTERNAL_ID_UNSUPPORTED` | 422 | Class lacks the external-id column (`ExternalIdentifierExtension` not applied) |
| `TOKEN_RESOLUTION_FAILED` | 422 | A `$palette`/`$button` color token is malformed or unresolvable |
| `ELEMENT_NOT_ALLOWED_ON_PAGE` | 422 | An element write (composition, batch, or generic upsert) is being attached to an `ElementalArea` whose owning page's Elemental config (`allowed_elements`/`disallowed_elements`) doesn't permit that element class — the error message lists the page's actual allowed types (#64) |
| `PAYLOAD_INVALID` | 400 | Malformed payload shape: bad relation value, missing polymorphic `class` hint, `..` in a folder path, bad `conflict`/`mode` value, missing `filename`, invalid `_stage`, unsupported filter modifier, missing `page.match`, etc. |
| `HOMEPAGE_CONVERSION_FORBIDDEN` | 403 | Converting the site home page's class without `force: true` |
| `ASSET_READ_FAILED` | 502 | Uploaded binary is empty/unreadable |
| `FEATURE_UNAVAILABLE` | 501 | Endpoint needs an optional integration that isn't installed (elemental, linkfield, essentials-tools, elemental-templates) |
| `SERVER_ERROR` | 500 | Uncaught exception. Dev/test environments get `ClassName: message`; production gets an opaque `"Internal server error."` |
| `ROLLBACK_UNVERIFIED` | 500 | An atomic batch (`docs/en/07_batch-operations.md`) failed and the transaction rollback path ran, but re-checking every `created` result — and every `deleted` result whose mode could have reached the draft row (`archive`, or any mode on an unversioned class) — by id afterward found at least one in a state that contradicts the claimed rollback (or the check itself failed to complete, which also fails toward this code rather than a false `rolledBack: true`). `error.details` carries the same full results array as a normal rollback failure — it does not narrow down which record(s) are affected — so re-check every `created`/`deleted` result in it directly before retrying (#70, #75) |

## `URLSEGMENT_COLLISION`: a warning code, not a thrown error

`ErrorCode::URLSEGMENT_COLLISION` exists in the enum (mapped to `409`) but is never raised as an
`ApiError` — it's used exclusively as a `code` inside a successful (`200`) response's
`warnings[]` array, when a write's requested `URLSegment` collided and SilverStripe silently
auto-suffixed it (`RecordWriter::write()`). A green response never hides that rewrite: check
`warnings` on any write that sets `URLSegment`, don't assume the segment you sent is the one that
was saved.

## Guarantees worth knowing

- **An unresolvable color token fails the write** rather than persisting a white-on-white (or
  otherwise broken) literal — see [Write payloads](06_write-payloads.md#color-tokens).
- **A rejected write payload changes nothing** — validation runs fully before anything is
  applied to the record (`WriteApplicator::applyFields()` collects every field problem before
  raising, rather than failing on the first one and leaving earlier fields half-applied).
