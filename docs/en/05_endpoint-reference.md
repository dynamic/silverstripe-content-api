# Endpoint reference

All routes below are relative to `/content-api/v1`. For the colymba `/api` generic-CRUD surface,
see [Quick start](01_quickstart.md) and the module README.

## Envelope

Every response — success or error — has the same shape:

```json
{ "data": ..., "meta": { ... }, "error": null }
```

On failure, `data` is `null` and `error` carries a structured, machine-readable body (`code`,
`status`, `message`, optional `details`) — see [Error codes](12_error-codes.md).

## Authentication

Every route requires the `X-Silverstripe-Apitoken` header. Failure modes are `401
UNAUTHENTICATED` (missing/unrecognized token) and `401 TOKEN_EXPIRED`. See
[Authentication](03_authentication.md).

## Routes

| Method | Path | Purpose |
|---|---|---|
| `GET` | `auth/session` | Token introspection: member, held permission codes, expiry |
| `GET` | `records/$ClassRef` | List records, with filtering/sorting/pagination/stage |
| `GET` | `records/$ClassRef/$ID` | Read one record — numeric id or `ext:<external-id>` |
| `PATCH` | `records/$ClassRef/$ID` | Update one record's fields (live-safe, not population-gated) |
| `GET` | `records/$ClassRef/$ID/parity` | Draft/live field diff for a record and its owned tree |
| `GET` | `fingerprint` | Deterministic, path-keyed content snapshot + reachability check |
| `POST` | `records/$ClassRef/$ID/publish\|unpublish\|archive` | Stage actions (`{"mode": "..."}` on publish and unpublish) |
| `POST` | `batch` | Ordered `create\|upsert\|update\|delete` operations |
| `POST` | `compositions/page` | Atomic full-page composition |
| `POST` | `assets` | Asset ingestion (multipart/base64) |
| `GET` | `assets/$ID` | Read one asset |
| `POST` | `pages/$ID/convert` | Change a page's class |
| `POST` | `pages/$ID/apply-template` | Apply an elemental-templates Template |
| `GET` | `schema`, `schema/site` | Site-level schema |
| `GET` | `schema/$ClassRef` | Per-class payload contract |
| `GET` | `` (index) | `{"name": "silverstripe-content-api", "version": "v1"}` |

> `PATCH records/$ClassRef/$ID` updates one record's fields; there is no `PUT`, single-record
> create or delete on this surface. Creates, relations and multi-record work go through
> `POST batch` or a composition — see [Batch operations](07_batch-operations.md).

## `GET records/$ClassRef`

List with query-string filtering, sorting, and pagination.

| Param | Notes |
|---|---|
| `Field=value` | Exact match filter. An empty value (`Title=`) matches records whose field is empty or `NULL` (also under `ExactMatch`, `not`, `case`, `nocase`); it applies to text fields only, anything else is `400 PAYLOAD_INVALID` |
| `Field__Modifier=value` | e.g. `Title__PartialMatch=Home`. Modifiers: `ExactMatch, PartialMatch, StartsWith, EndsWith, GreaterThan, GreaterThanOrEqual, LessThan, LessThanOrEqual, not, nocase, case` |
| `sort` | `Field` (ASC) or `-Field` (DESC); comma-separated for multiple |
| `limit` | A positive integer. Default `50`, capped at `500` regardless of what's requested. Anything else (`0`, negative, non-numeric, `1.9`) is `400 PAYLOAD_INVALID` |
| `offset` | A non-negative integer. Default `0`; anything else is `400 PAYLOAD_INVALID` |
| `_stage` | `draft` (default) or `live`. Underscored because bare `stage` is SilverStripe's own reserved staging param, consumed by the Versioned middleware before any controller runs |

A comma-containing filter value is split into an `IN (...)`-style array match. Filtering and
sorting accept only columns a response can show (`RecordSerializer::readableColumns()`): the
class's `api_fields` when set (otherwise every db field), the FK column of each emitted has_one,
`ID` and the external-id column — never a `hidden_fields` column or anything outside
`api_fields`, since a predicate on a withheld column would leak its value through which rows
match. Anything else returns `422 UNKNOWN_FIELD`; an unsupported modifier returns `400
PAYLOAD_INVALID`.

Every response from this API carries `Cache-Control: no-cache, no-store, must-revalidate,
private`: responses are per-token and can carry draft content.

**Visibility is resolved before pagination**, row by row (`canView()` has no SQL pushdown) — so
`meta.total` reflects only records the caller can view, and a page is never short a record that
was silently filtered out after the fact. This bounds memory to `limit` held records at a time,
not the full visible set.

Response `meta`: `total`, `limit`, `offset`, `stage`.

## `GET records/$ClassRef/$ID`

`$ID` is a numeric id or `ext:<external-id>` (looked up via
[`ExternalIdResolver`](06_write-payloads.md#external-ids)). Same `_stage` param as list reads.
`400 PAYLOAD_INVALID` for a malformed id; `404 NOT_FOUND` if it doesn't resolve.

## `GET records/$ClassRef/$ID/parity`

"Does this record, and everything it `$owns`, match between draft and live." `400
PAYLOAD_INVALID` for a non-Versioned class (nothing to compare); `404 NOT_FOUND` for an
unresolvable id. Query params: `include=none` skips the owned-tree walk (default: walked);
`depth=N` caps how far it recurses. Full reference:
[Publishing & stages](10_publishing-and-stages.md#draftlive-parity).

## `GET fingerprint`

A deterministic, path-keyed snapshot of the site's content, meant to be diffed — the same
environment before/after a batch, or two different environments ahead of a replay. Pages are
keyed by URL path rather than id (ids churn across a rebuild/sync/environment boundary; paths
don't) and `violations` asserts the reachability invariant a plain snapshot can't: a live page (or
live related record) whose path runs through a non-live ancestor — always computed regardless of
`classes=`, which only restricts which SECTIONS appear in the response, never which reachability
problems get reported. Query params: `classes=` (comma list of section refs — `pages` plus any
project-configured `related` ref — restricting the response; an unrecognized ref is rejected,
`400 PAYLOAD_INVALID`; omit for everything), `includeIds=true` (adds ids back in, off by default).
Applies the same class- and record-level access control as every other read endpoint, per row — a
class not exposed to the content API at all appears in `meta.skipped`; a specific row this token
can't view (e.g. a draft-only page, or one under a draft-changed parent, without
`VIEW_DRAFT_CONTENT`) is simply absent from the
response. Full reference: [Verification](16_verification.md#fingerprint).

## `PATCH records/$ClassRef/$ID`

Updates one existing record (numeric id or `ext:<external-id>`). Body:

```json
{ "fields": { "Title": "Fixed typo" }, "publish": "none" }
```

`fields` (required, non-empty) takes the same shapes as a batch op's `fields` (see
[Write payloads](06_write-payloads.md)); `publish` is `none` (default, the change stays on draft)
or `single`. Any other key, relations included, is `400 PAYLOAD_INVALID`: use `POST batch` for
creates, relations and multi-record changes.

It runs the batch `update` checks: the `update` verb on the record's own class plus `canEdit()`,
the field write policy and allowlist (`422 READONLY_FIELD`/`UNKNOWN_FIELD`), and the `action`
verb when `publish` is `single` (`403 FORBIDDEN_CLASS`, nothing written). It needs
`CONTENT_API_ACCESS` only, not `CONTENT_API_POPULATE`, and is **not** behind the population
environment gate, so it is the write that works on a live site. A project can close it per
environment type with `RecordUpdateHandler.disabled_environments` (`403 ENV_FORBIDDEN`; see
[Configuration](02_configuration.md#recordupdatehandler)); `GET schema/site` reports
`recordUpdateEnabled`. Which fields it can reach is the class's write policy, and `publish:
"single"` publishes the record's whole draft, pending editor changes included: see
[Live writes](04_security-model.md#live-writes-patch-recordsclassrefid).

Response: `data` is the updated record (with its `stage`), `meta.operation` is `updated`, and
`meta.warnings` is present when the write raised any (e.g. `ACTION_VERB_MISSING`).

## `POST records/$ClassRef/$ID/{action}`

`{action}` is `publish`, `unpublish`, or `archive`. `publish` accepts `{"mode": "..."}` to
select a [publish mode](10_publishing-and-stages.md#publish-modes) (`{"recursive": true}`
remains a legacy shorthand for `mode: "recursive"`) — `mode: "subtree"` alone also accepts
`{"liveOnly": true}`; `mode: "subtree"` or `"owns"` accept `{"dryRun": true}`. `unpublish`
also accepts `{"mode": "owns"}` to select an [unpublish mode](10_publishing-and-stages.md#unpublish-modes)
— `mode: "owns"` cascades to the record's owned tree minus shared assets, and alone accepts
`{"dryRun": true}` too; a plain `single`-mode unpublish and `archive` have no dry-run support
at all. See [Publishing & stages](10_publishing-and-stages.md).

## `POST assets` / `GET assets/$ID`

See [Assets](09_assets.md).

## `POST pages/$ID/convert`

Population-domain, like batch and compositions: requires `CONTENT_API_POPULATE` and passes
[environment gating](04_security-model.md#environment-gating).

Changes a page's class via `newClassInstance()`. Body: `{"className": "...", "publish":
"none|single|recursive|subtree|owns", "force": false}`. Refuses to convert the site home page
unless `force: true` (`403 HOMEPAGE_CONVERSION_FORBIDDEN`). A no-op (same class already) returns
without error.

A class change is a structural change, so both the page's current class and the target class
must declare the bare `convert` verb in `api_access` (`403 FORBIDDEN_CLASS` otherwise), in
addition to `update` (and `action` when `publish` isn't `none`). `api_access: true` includes it.
The class change and the publish run in one transaction: a refused publish rolls the conversion
back (`(convert rolled back)` in the message). A publishing convert reports what it published in
`meta.published`.

## `POST pages/$ID/apply-template`

Population-domain: requires `CONTENT_API_POPULATE` and passes
[environment gating](04_security-model.md#environment-gating).

Applies an `elemental-templates` `Template`'s element composition to a page. Requires
`dynamic/silverstripe-elemental-templates` (`501 FEATURE_UNAVAILABLE` otherwise). Body:
`{"templateId": 3, "publish": "none|recursive"}`.

The page class needs `update` plus `canEdit()`, and the template needs `canView()`. Every class
the apply will create (each template element's class and every child class `duplicate()`
cascades to) needs the `create` verb plus `canCreate()`, checked before anything is written.

`"recursive"` publishes the page, its elemental area, every element on it, and each element's own
duplicated descendants — the records `DataObject::duplicate()` created when the template was
applied, of whatever relation type, versioned ones only (an unversioned child has no live stage
and is skipped) (#174). Note "every element on it", not "every element the template added": a
pre-existing element's children are published too.

Every one of those classes is authorization-checked (`action` verb + `canPublish()`) before anything
is **published**, and the whole call — the template's draft write included — rolls back if one is
refused. See [Publishing and stages](10_publishing-and-stages.md).

## `POST batch`, `POST compositions/page`

Full reference: [Batch operations](07_batch-operations.md),
[Page compositions](08_page-compositions.md). Both are population-domain endpoints, as are
`POST assets` and the page actions above — they additionally require `CONTENT_API_POPULATE` and pass
[environment gating](04_security-model.md#environment-gating).

## `GET schema`, `GET schema/$ClassRef`

Full reference: [Schema introspection](11_schema-introspection.md). `GET schema/$ClassRef` for a
mapped class that grants no verbs is `403 FORBIDDEN_CLASS`, as on the records routes.
