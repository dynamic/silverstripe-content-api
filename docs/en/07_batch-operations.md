# Batch operations

`POST batch` runs an ordered list of write operations with a per-operation result — the
"agent self-correction contract": a caller can inspect exactly which operations succeeded and
retry only the failed ones.

```json
{
  "atomic": false,
  "defaultPublish": "none",
  "operations": [
    { "op": "upsert", "class": "ElementContent", "externalId": "about-intro",
      "fields": { "Title": "Who We Are" }, "publish": "single" },
    { "op": "update", "class": "ElementContent", "id": "ext:hero-block",
      "fields": { "Title": "Updated" } },
    { "op": "delete", "class": "ElementContent", "id": 42, "mode": "archive" }
  ]
}
```

Population-domain endpoint: requires `CONTENT_API_POPULATE` and passes
[environment gating](04_security-model.md#environment-gating).

## Operation shape

| Key | Applies to | Notes |
|---|---|---|
| `op` | all | `create`, `upsert`, `update`, or `delete` |
| `class` | all | Short class ref, resolved via `ClassRegistry` |
| `id` | `update`, `delete` | Numeric id or `ext:<external-id>` |
| `externalId` | `create`, `upsert`, `update` | Sets/matches the external-id column |
| `fields` | `create`, `upsert`, `update` | See [Write payloads](06_write-payloads.md#fields) |
| `relations` | `create`, `upsert`, `update` | See [Write payloads](06_write-payloads.md#relations-has_many--many_many) |
| `publish` | `create`, `upsert`, `update` | `none`/`single`/`recursive`/`subtree`; falls back to `defaultPublish` when omitted — see [Publishing & stages](10_publishing-and-stages.md#publish-modes) |
| `mode` | `delete` | `archive` (default), `unpublish`, or `hard` — see [below](#delete-modes) |
| `force` | `delete` with `mode: "unpublish"` or `mode: "archive"` | Bypasses the descendant-cascade guard — see [below](#delete-modes) |

`update`/`delete` require either `id` or `externalId` to locate the target
(`400 PAYLOAD_INVALID` if neither is present).

## Atomicity

- **Non-atomic (default, `"atomic": false`)**: an operation that fails reports an `error` result
  for that index and the rest continue independently.
- **`"atomic": true`**: the whole batch runs inside one DB transaction. The first failing
  operation rolls everything back; the response reports `422 VALIDATION_FAILED` with the
  partial results (`rolledBack: true`) attached as `error.details`.

## Response shape

```json
{
  "results": [
    { "index": 0, "status": "created", "id": 12, "externalId": "about-intro", "stage": {...} },
    { "index": 1, "status": "updated", "id": 8, "externalId": "hero-block" },
    { "index": 2, "status": "deleted", "id": 42 }
  ],
  "summary": { "created": 1, "updated": 1, "deleted": 1, "skipped": 0, "errors": 0 }
}
```

`status` per result is `created`/`updated`/`deleted`/`error`. A failed operation reports
`{"index": n, "status": "error", "error": {...}}` (same structured shape as the top-level
envelope error) instead of aborting the whole batch (unless `atomic: true`).

Every operation runs with `Versioned::set_stage(DRAFT)` — writes always land on draft
regardless of any `_stage` the caller might otherwise expect; publish state is controlled
exclusively by `publish`/`defaultPublish`.

## Delete modes

| Mode | Effect on a **versioned** record | Applies to |
|---|---|---|
| `archive` (default) | Removed from both stages, recoverable via version history (`doArchive()`) — refuses with `409 UNPUBLISH_STRANDS_DESCENDANTS` if it would cascade-remove `Hierarchy` descendants in either stage; pass `force: true` to bypass, see [Publishing & stages](10_publishing-and-stages.md#unpublishing-or-archiving-a-hierarchy-record-the-descendant-cascade-guard) | Versioned + unversioned |
| `unpublish` | Removed from live only, draft kept (`doUnpublish()`) — same guard, checked against live descendants only | Versioned + unversioned |
| `hard` | Rejected — see below | **Unversioned classes only** |

**On an unversioned class, every mode converges on a real `delete()`** — `archive` and
`unpublish` are not distinct/recoverable operations there the way they are for a versioned
record; all three mode names produce the same hard delete. Versioned classes (e.g.
`SiteTree`/`BaseElement` subclasses) reject `hard` up front with `400 PAYLOAD_INVALID` listing
only the modes that record can actually use (`archive`, `unpublish`) — the error never
advertises a mode that would fail anyway.
