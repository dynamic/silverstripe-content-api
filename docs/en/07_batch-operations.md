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
| `publish` | `create`, `upsert`, `update` | `none`/`single`/`recursive`/`subtree`/`owns`; falls back to `defaultPublish` when omitted — see [Publishing & stages](10_publishing-and-stages.md#publish-modes) |
| `mode` | `delete` | `archive` (default), `unpublish`, or `hard` — see [below](#delete-modes) |
| `force` | `delete` with `mode: "unpublish"` or `mode: "archive"` | Bypasses the descendant-cascade guard — see [below](#delete-modes) |

`update`/`delete` require either `id` or `externalId` to locate the target
(`400 PAYLOAD_INVALID` if neither is present).

## Atomicity

- **Non-atomic (default, `"atomic": false`)**: an operation that fails reports an `error` result
  for that index and the rest continue independently.
- **`"atomic": true`**: the whole batch runs inside one DB transaction. The first failing
  operation rolls everything back; the response reports `422 VALIDATION_FAILED` with the
  partial results (`rolledBack: true`) attached as `error.details`. Before `rolledBack: true`
  is reported, every `created` result is re-checked by id against the database, and every
  `deleted` result is too **when the delete could actually have reached the draft row** —
  `mode: "archive"` always does, and any mode on an unversioned class does (every delete mode
  converges on a real `delete()` there); `mode: "unpublish"` on a versioned class only touches
  the live stage, so it's correctly skipped rather than checked against a draft row it never
  touched. `updated` results are checked too: the declared `fields` keys are snapshotted before
  the write, and the row is re-read afterward to confirm they're genuinely back at their prior
  values — **but only the declared `fields` keys**. An `update` whose payload carries only
  `relations` (no `fields` at all) has nothing to snapshot, so it can't be verified and the
  batch reports `ROLLBACK_UNVERIFIED` rather than a false `rolledBack: true`; relation changes
  themselves are never covered by this check, on any `update`. Verification also only reads
  DRAFT — an `update` with `publish` set also wrote LIVE, which isn't independently re-checked.
  If any checked `created`/`deleted`/`updated` record's state contradicts the claimed rollback
  — confirmed possible when a non-`Throwable` PHP diagnostic (e.g. a deprecation notice from
  application code this module doesn't control) fires mid-write — the response reports
  `500 ROLLBACK_UNVERIFIED` instead. That response does **not** narrow down which record(s) are
  still present; it carries the same full `error.details` block as a normal rollback failure,
  so re-check every `created`/`deleted`/`updated` result in it by hand before retrying.
  See `docs/en/12_error-codes.md`.

## Dry run

`"dryRun": true` runs the batch exactly as a real request would — the same authorization,
class/externalId/relation resolution, payload validation and model `validate()` (none of that
surfaces except by actually attempting the write) — inside a transaction that is
**unconditionally rolled back afterward**, regardless of `atomic` or whether every op succeeded.
Subsumes the "create a scratch record, then delete it" pattern sometimes used to prove a token
is writable ahead of a real batch: `dryRun` gives the same answer without ever touching the
database for real.

```json
{
  "dryRun": true,
  "operations": [
    { "op": "create", "class": "ElementContent", "fields": { "Title": "Preview" } }
  ]
}
```

- Still requires `CONTENT_API_POPULATE` and passes environment gating — validate-only still
  authorizes and resolves everything a real run would, which alone leaks schema/permission
  information a caller shouldn't get for free.
- The response **replaces** the normal envelope rather than augmenting it: every `status` value
  is prefixed `would` — `created`/`updated`/`deleted` become `wouldCreate`/`wouldUpdate`/
  `wouldDelete` (`error` is unchanged) — in both `results[].status` and `summary`, so a caller
  inspecting `status` can never mistake this for a confirmed write. `meta` carries
  `{"operation": "batchDryRun", "atomic": <bool>}`.
- Non-atomic (`"atomic": false`, the default) still reports per-op errors independently, same as
  a real non-atomic run — a dry run predicts exactly what a real run would report, it doesn't
  change the reporting shape. `atomic: true` still aborts on the first op failure and reports the
  same `422 VALIDATION_FAILED` envelope a real atomic failure would, with `rolledBack` always
  `true` (the whole batch was wrapped in a transaction that never had a chance to commit).
- Rollback is verified the same way an atomic failure's is (see "Atomicity" above) — "wrapped in
  a transaction that gets rolled back" is exactly the mechanism proven unreliable on its own by
  #70. A dry run that fails verification is the loudest possible failure: it means this "safe
  preflight" call may have just written real data, reported as `500 ROLLBACK_UNVERIFIED`, never
  folded into the normal dry-run response — and, deliberately, **not** mapped through the `would*`
  vocabulary: `error.details[0].results[]` uses real verbs (`created`/`updated`/`deleted`) and a
  real delete's `deleted: true`, because at that point the caller genuinely doesn't know whether
  the write committed for real, and real verbs are the honest signal for that state. Verification
  is **lenient** about an `update` op that
  declared only `relations` (no `fields`) — there's nothing to check for it either way, so a dry
  run doesn't fail the whole batch over it the way a real atomic failure's stricter check does;
  it's simply not part of what got verified. Verification also only ever reads DRAFT, same as a
  real atomic failure's — a dry run whose ops carry `publish` also wrote LIVE inside the
  transaction, and that side isn't independently re-checked before reporting "verified".
- **Ids in a dry-run response are ephemeral** — a rolled-back insert still consumes an
  `AUTO_INCREMENT` value, so don't treat a `wouldCreate` result's `id` as reusable or as evidence
  of what a real run's id will be. A `wouldDelete` result's `deleted` field is always `false` (the
  record still exists) even though the same field is `true` on a real delete's response.
- **Rollback covers the database only, on the connection this module's writes use** —
  `DbTransaction::run()` layered under the framework's own nested-transaction (savepoint)
  support correctly rolls back an ordinary `onBeforeWrite()`/`ValueTransformer` side effect that
  writes through the ORM, even to an unrelated table `dryRun`'s own verification never looks at
  (#203; see `BatchTest::testDryRunRollsBackASideEffectWriteToAnUnrelatedTable()`). What no DB
  transaction can undo is a side effect OUTSIDE the database — an HTTP call, a queued job, a
  write to an external cache or service (confirmed live: `EmbedObject` rows from an oEmbed
  lookup survived both a rolled-back composition and a `dryRun` probe). Project code with a
  side effect like that should check `Dynamic\ContentApi\Write\DryRunContext::isActive()` in its
  own hook/transformer and skip it when true — this module can't do that on a project class's
  behalf.
- `dryRun` is a `POST batch` feature only — every other write endpoint (`compositions/page`,
  `pages/$ID/convert`/`apply-template`, `records/$ClassRef/$ID/unpublish`/`archive`, `assets`)
  rejects it outright (`400 PAYLOAD_INVALID`) rather than silently ignoring it.
  `records/$ClassRef/$ID/publish` is the one exception — `dryRun` there means
  [subtree-publish dry-run](10_publishing-and-stages.md#publish-modes), a different, older (#102)
  feature with its own `wouldPublish` response shape, not this one.

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
