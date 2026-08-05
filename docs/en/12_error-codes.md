# Error codes

Every error response carries a stable, machine-readable code — a caller (human or agent) should
branch on `error.code`, never on `error.message` text. Source:
[`ErrorCode.php`](../../src/Errors/ErrorCode.php).

```json
{ "data": null, "meta": {}, "error": { "code": "VALIDATION_FAILED", "status": 422,
  "message": "...", "details": [ { "field": "Title", "code": "UNKNOWN_FIELD", "message": "..." } ] } }
```

`details` is present on multi-field failures (write validation, batch atomic rollback) and
absent otherwise.

| Code | HTTP | Triggered by |
|---|---|---|
| `UNAUTHENTICATED` | 401 | No token header, or `getOwner()` couldn't resolve a member from it |
| `TOKEN_EXPIRED` | 401 | Token past its strict `ApiTokenExpire` |
| `FORBIDDEN` | 403 | Member lacks `CONTENT_API_ACCESS` or `CONTENT_API_POPULATE` |
| `FORBIDDEN_CLASS` | 403 | Class doesn't expose the requested verb via `api_access`/`content_api_access` |
| `FORBIDDEN_RECORD` | 403 | The model's own `canView`/`canEdit`/`canDelete`/`canCreate` denies |
| `ENV_FORBIDDEN` | 403 | Population endpoint called outside `population_enabled_environments` without the override |
| `UNKNOWN_CLASS` | 404 | Class ref not in the merged models map, or the class doesn't exist |
| `NOT_FOUND` | 404 | Record / external id / related record not found |
| `METHOD_NOT_ALLOWED` | 405 | Wrong HTTP verb for the route |
| `MULTIPLE_MATCHES` | 409 | An external id (or a `urlSegment` page match) resolves to more than one record |
| `ALREADY_EXISTS` | 409 | A create conflicts with an existing record |
| `ASSET_CONFLICT` | 409 | Asset path conflict not resolvable by the chosen `conflict` mode |
| `UNPUBLISH_STRANDS_DESCENDANTS` | 409 | `unpublish` or `archive` (stage action, or batch `delete` with `mode: "unpublish"`/`"archive"`) would cascade-delete `Hierarchy` descendants currently nested under the record (`SiteTree.enforce_strict_hierarchy`, the framework default) — pass `force: true` to bypass and accept the loss (#71) |
| `VALIDATION_FAILED` | 422 | A model's own `ValidationException` on write (mapped to structured `details`, never a raw exception message) |
| `UNKNOWN_FIELD` | 422 | Payload key isn't a recognized db field/relation, under `api_unknown_fields: strict` |
| `READONLY_FIELD` | 422 | Field/relation isn't writable per the guarded/allowlist policy, is protected, or is a bare polymorphic `{Name}Class` column set directly |
| `UNKNOWN_RELATION` | 422 | has_many/many_many relation name doesn't exist on the class |
| `UNRESOLVED_REF` | 422 | A composition `$ref` alias never resolves |
| `CIRCULAR_REF` | 422 | A cycle among only-deferred composition elements (mutual unresolved `$ref`s) |
| `EXTERNAL_ID_UNSUPPORTED` | 422 | Class lacks the external-id column (`ExternalIdentifierExtension` not applied) |
| `TOKEN_RESOLUTION_FAILED` | 422 | A `$palette`/`$button` color token is malformed or unresolvable |
| `PAYLOAD_INVALID` | 400 | Malformed payload shape: bad relation value, missing polymorphic `class` hint, `..` in a folder path, bad `conflict`/`mode` value, missing `filename`, invalid `_stage`, unsupported filter modifier, missing `page.match`, etc. |
| `HOMEPAGE_CONVERSION_FORBIDDEN` | 403 | Converting the site home page's class without `force: true` |
| `ASSET_READ_FAILED` | 502 | Uploaded binary is empty/unreadable |
| `FEATURE_UNAVAILABLE` | 501 | Endpoint needs an optional integration that isn't installed (elemental, linkfield, essentials-tools, elemental-templates) |
| `SERVER_ERROR` | 500 | Uncaught exception. Dev/test environments get `ClassName: message`; production gets an opaque `"Internal server error."` |
| `ROLLBACK_UNVERIFIED` | 500 | An atomic batch (`docs/en/07_batch-operations.md`) failed and the transaction rollback path ran, but re-checking every `created` result by id afterward found at least one still present (or the check itself failed to complete, which also fails toward this code rather than a false `rolledBack: true`). `error.details` carries the same full results array as a normal rollback failure — it does not narrow down which record(s) are still present — so re-check every `created` result in it directly before retrying (#70) |

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
