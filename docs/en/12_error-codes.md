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
| `FORBIDDEN` | 403 | Member lacks `CONTENT_API_ACCESS` or `CONTENT_API_POPULATE`, or (on `schema`) holds neither `CONTENT_API_ACCESS` nor `CONTENT_API_SCHEMA` |
| `FORBIDDEN_CLASS` | 403 | Class doesn't expose the requested verb via `api_access`/`content_api_access` |
| `FORBIDDEN_RECORD` | 403 | The model's own `canView`/`canEdit`/`canDelete`/`canCreate` denies |
| `ENV_FORBIDDEN` | 403 | Population endpoint called outside `population_enabled_environments` without the override. `details[0]` carries `environment`, `envVar`, and `populationEnabledEnvironments` so a caller can distinguish this from an ACL failure and act on it programmatically (#126) |
| `UNKNOWN_CLASS` | 404 | Class ref not in the merged models map, or its mapped FQCN doesn't exist. `details[0].code` distinguishes `CLASS_NOT_MAPPED` (a real, registerable class exists with that name but has no `models:` entry — `details[0].message` names the FQCN), `CLASS_ALREADY_MAPPED` (that class is already registered, under a different ref — `details[0].message` names the existing ref, use that instead of adding a duplicate), and `CLASS_NOT_FOUND` (a `models:` entry exists but the FQCN it points at doesn't autoload), when the module can tell which. Never suggests a class the module hardcodes as never-exposable (`Member`/`Group`/`Permission`/etc.) regardless of project config |
| `NOT_FOUND` | 404 | Record / external id / related record not found, or an unknown `{action}` segment on `records/$ClassRef/$ID/{action}` or `pages/$ID/{action}` |
| `METHOD_NOT_ALLOWED` | — | Reserved, never raised. A wrong HTTP verb or an unknown path gets SilverStripe's own `text/plain` 404, outside the envelope and with no `error.code` |
| `MULTIPLE_MATCHES` | 409 | An external id (or a `urlSegment` page match) resolves to more than one record |
| `ALREADY_EXISTS` | 409 | A create conflicts with an existing record |
| `ASSET_CONFLICT` | 409 | An upload's `externalId` is held by a file at a different path, or the file at the path already carries a different `externalId` (#240) — the API won't create a second file with the key or re-key the existing one |
| `UNPUBLISH_STRANDS_DESCENDANTS` | 409 | `unpublish` or `archive` (stage action, or batch `delete` with `mode: "unpublish"`/`"archive"`) would cascade-delete `Hierarchy` descendants currently nested under the record (`SiteTree.enforce_strict_hierarchy`, the framework default) — pass `force: true` to bypass and accept the loss (#71) |
| `STAGE_CLASS_MISMATCH` | 409 | An unpublish/archive/delete of a record whose live row is a different class from its draft (a draft-only class conversion). Publish the conversion first (#239) |
| `CROSS_PAGE_REPARENT` | 409 | A composition's server-derived element placement (never an explicit client `fields.ParentID` write, which stays governed only by `ELEMENT_NOT_ALLOWED_ON_PAGE`) would move an already-parented element onto a DIFFERENT page's `ElementalArea` — almost always means `page.match` resolved to the wrong page, since a global `externalId` lookup matched a record that already belongs elsewhere (#201) |
| `VALIDATION_FAILED` | 422 | A model's own `ValidationException` on write (mapped to structured `details`, never a raw exception message) |
| `UNKNOWN_FIELD` | 422 | Payload key isn't a recognized db field/relation, under `api_unknown_fields: strict` |
| `READONLY_FIELD` | 422 | Field/relation isn't writable per the guarded/allowlist policy, is protected, or is a bare polymorphic `{Name}Class` column set directly |
| `INVALID_VALUE` | 422 | A value doesn't fit its column's own constraints — currently: an Enum/MultiEnum field given a value outside its declared list |
| `UNKNOWN_RELATION` | 422 | has_many/many_many relation name doesn't exist on the class |
| `UNRESOLVED_REF` | 422 | A composition `$ref` alias never resolves and no stuck element declares it |
| `CIRCULAR_REF` | 422 | Every unresolved composition `$ref` is declared by a stuck element (a cycle, including a self-reference) |
| `EXTERNAL_ID_UNSUPPORTED` | 422 | Class lacks the external-id column (`ExternalIdentifierExtension` not applied) |
| `TOKEN_RESOLUTION_FAILED` | 422 | A `$palette`/`$button` color token is malformed or unresolvable |
| `ELEMENT_NOT_ALLOWED_ON_PAGE` | 422 | An element write (composition, batch, or generic upsert) is being attached to an `ElementalArea` whose owning page's Elemental config (`allowed_elements`/`disallowed_elements`) doesn't permit that element class — the error message lists the page's actual allowed types (#64) |
| `ACTION_VERB_MISSING` | — | Warning code, never thrown: see [Warning codes](#warning-codes-200-responses) |
| `VALUE_SANITISED` | — | Warning code, never thrown: see [Warning codes](#warning-codes-200-responses) |
| `URLSEGMENT_COLLISION` | — | Warning code, never thrown: see [Warning codes](#warning-codes-200-responses) |
| `PAYLOAD_TOO_LARGE` | 413 | A multipart upload exceeds the server's `upload_max_filesize` or `post_max_size` (the message names the limit) |
| `PAYLOAD_INVALID` | 400 | Malformed payload shape: bad relation value, missing polymorphic `class` hint, `..` in a folder path, bad `conflict`/`mode` value, missing `filename`, invalid `_stage`, unsupported filter modifier, missing `page.match`, etc. |
| `HOMEPAGE_CONVERSION_FORBIDDEN` | 403 | Converting the site home page's class without `force: true` |
| `ASSET_READ_FAILED` | 502 | Uploaded binary is empty/unreadable |
| `FEATURE_UNAVAILABLE` | 501 | Endpoint needs an optional integration that isn't installed (elemental, linkfield, essentials-tools, elemental-templates) |
| `SERVER_ERROR` | 500 | Uncaught exception. Dev/test environments get `ClassName: message`; production gets an opaque `"Internal server error."` |
| `ROLLBACK_UNVERIFIED` | 500 | An atomic batch (`docs/en/07_batch-operations.md`) failed and the transaction rollback path ran, but re-checking every `created` result — and every `deleted` result whose mode could have reached the draft row (`archive`, or any mode on an unversioned class) — by id afterward found at least one in a state that contradicts the claimed rollback (or the check itself failed to complete, which also fails toward this code rather than a false `rolledBack: true`). `error.details` carries the same full results array as a normal rollback failure — it does not narrow down which record(s) are affected — so re-check every `created`/`deleted` result in it directly before retrying (#70, #75) |

## Warning codes (200 responses)

A successful write can carry `warnings`: a list of `{code, message, field?}` objects naming
something the request asked for that didn't land exactly as sent. A green response never hides
these, so read `warnings` on every write. They appear at:

| Response | Location |
|---|---|
| `POST batch` | `data.results[].warnings` (only on a result that has any) |
| `POST compositions/page` | `data.page.warnings` (the page write's own warnings, plus the `areaRelation` mismatch), `data.elements[].warnings`, `data.assets[].warnings` |
| `POST assets` | `data.warnings` |

| Code | Emitted when |
|---|---|
| `URLSEGMENT_COLLISION` | A write's requested `URLSegment` was taken and SilverStripe saved a suffixed one (`RecordWriter::write()`). Never thrown as an error. |
| `ACTION_VERB_MISSING` | A write to a `Versioned` class whose `api_access`/`content_api_access` grants `create` or `update` but not `action`, so nothing can ever publish it (#198); also an asset upload that falls back to draft-only for the same reason. `action` has no HTTP method of its own and must be listed as a bare token (see [Configuration](02_configuration.md#classregistry)). Never thrown as an error. |
| `VALUE_SANITISED` | HTML sanitising removed markup from an HTML-typed field that the `html_editor_config` editor config does not allow (see [Configuration](02_configuration.md#writeapplicator)). The write lands minus that markup; a pure serialisation change is not reported. colymba's `/api` sanitises the same way but has no warnings channel. Never thrown as an error. |
| `UNKNOWN_FIELD` | Under `api_unknown_fields: lenient`, a payload key (or a relation `extraFields` key) that matches nothing was ignored. Under `strict` the same key is a `422`. |
| `FEATURE_UNAVAILABLE` | A multirelational polymorphic has_one was set without its `{Name}Relation` column, which this API can't set. Unrelated to the `501` meaning in the table above. |
| `PAYLOAD_INVALID` | A composition gave different top-level `areaRelation` and `page.areaRelation` values; `page.areaRelation` was used. |
| `SERVER_ERROR` | A composition asset was saved but its post-commit publish failed (the asset is left out of `published`). |

The 409/422 statuses the enum maps `URLSEGMENT_COLLISION`, `ACTION_VERB_MISSING` and
`VALUE_SANITISED` to are never sent.

## Guarantees worth knowing

- **An unresolvable color token fails the write** rather than persisting a white-on-white (or
  otherwise broken) literal — see [Write payloads](06_write-payloads.md#color-tokens).
- **A rejected write payload changes nothing** — validation runs fully before anything is
  applied to the record (`WriteApplicator::applyFields()` collects every field problem before
  raising, rather than failing on the first one and leaving earlier fields half-applied).
