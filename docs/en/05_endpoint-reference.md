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
| `GET` | `records/$ClassRef/$ID/parity` | Draft/live field diff for a record and its owned tree |
| `GET` | `fingerprint` | Deterministic, path-keyed content snapshot + reachability check |
| `POST` | `records/$ClassRef/$ID/publish\|unpublish\|archive` | Stage actions (`{"recursive": true}` on publish) |
| `POST` | `batch` | Ordered `create\|upsert\|update\|delete` operations |
| `POST` | `compositions/page` | Atomic full-page composition |
| `POST` | `assets` | Asset ingestion (multipart/base64) |
| `GET` | `assets/$ID` | Read one asset |
| `POST` | `pages/$ID/convert` | Change a page's class |
| `POST` | `pages/$ID/apply-template` | Apply an elemental-templates Template |
| `GET` | `schema` | Site-level schema |
| `GET` | `schema/$ClassRef` | Per-class payload contract |
| `GET` | `` (index) | `{"name": "silverstripe-content-api", "version": "v1"}` |

> There is no `PUT`/`PATCH` route on this surface. Single-record field updates go through
> `POST batch` (`op: "update"`) or a composition's sparse element upsert — see
> [Batch operations](07_batch-operations.md).

## `GET records/$ClassRef`

List with query-string filtering, sorting, and pagination.

| Param | Notes |
|---|---|
| `Field=value` | Exact match filter |
| `Field__Modifier=value` | e.g. `Title__PartialMatch=Home`. Modifiers: `ExactMatch, PartialMatch, StartsWith, EndsWith, GreaterThan, GreaterThanOrEqual, LessThan, LessThanOrEqual, not, nocase, case` |
| `sort` | `Field` (ASC) or `-Field` (DESC); comma-separated for multiple |
| `limit` | Default `50`, capped at `500` regardless of what's requested |
| `offset` | Default `0` |
| `_stage` | `draft` (default) or `live`. Underscored because bare `stage` is SilverStripe's own reserved staging param, consumed by the Versioned middleware before any controller runs |

A comma-containing filter value is split into an `IN (...)`-style array match. Filtering/sorting
on an unrecognized field returns `422 UNKNOWN_FIELD`; an unsupported modifier returns `400
PAYLOAD_INVALID`.

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
can't view (e.g. a draft-only page without `VIEW_DRAFT_CONTENT`) is simply absent from the
response. Full reference: [Verification](16_verification.md#fingerprint).

## `POST records/$ClassRef/$ID/{action}`

`{action}` is `publish`, `unpublish`, or `archive`. `publish` accepts `{"mode": "..."}` to
select a [publish mode](10_publishing-and-stages.md#publish-modes) (`{"recursive": true}`
remains a legacy shorthand for `mode: "recursive"`) — `mode: "subtree"` alone also accepts
`{"liveOnly": true}`; `mode: "subtree"` or `"owns"` accept `{"dryRun": true}`. See
[Publishing & stages](10_publishing-and-stages.md).

## `POST assets` / `GET assets/$ID`

See [Assets](09_assets.md).

## `POST pages/$ID/convert`

Changes a page's class via `newClassInstance()`. Body: `{"className": "...", "publish":
"none|single|recursive|subtree|owns", "force": false}`. Refuses to convert the site home page
unless `force: true` (`403 HOMEPAGE_CONVERSION_FORBIDDEN`). A no-op (same class already) returns
without error.

## `POST pages/$ID/apply-template`

Applies an `elemental-templates` `Template`'s element composition to a page. Requires
`dynamic/silverstripe-elemental-templates` (`501 FEATURE_UNAVAILABLE` otherwise). Body:
`{"templateId": 3, "publish": "none|recursive"}`.

`"recursive"` publishes the page, its elemental area, every element on it, and each element's own
duplicated descendants — the records `DataObject::duplicate()` created when the template was
applied, of whatever relation type, versioned ones only (an unversioned child has no live stage
and is skipped) (#174). Note "every element on it", not "every element the template added": a
pre-existing element's children are published too.

Every one of those classes is authorization-checked (`action` verb + `canEdit()`) before anything
is **published**, and the whole call — the template's draft write included — rolls back if one is
refused. See [Publishing and stages](10_publishing-and-stages.md).

## `POST batch`, `POST compositions/page`

Full reference: [Batch operations](07_batch-operations.md),
[Page compositions](08_page-compositions.md). Both are population-domain endpoints — they
additionally require `CONTENT_API_POPULATE` and pass
[environment gating](04_security-model.md#environment-gating).

## `GET schema`, `GET schema/$ClassRef`

Full reference: [Schema introspection](11_schema-introspection.md).
