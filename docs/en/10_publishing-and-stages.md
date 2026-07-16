# Publishing & stages

## Draft/live model

SilverStripe's Versioned model applies: every write lands on **draft** first; **live** is a
separate, explicit publish step. This module makes that explicit in every response
(`RecordSerializer`'s `stage` block: `{"draft": true, "live": bool, "modifiedOnDraft": bool}`)
rather than leaving it implicit the way a direct ORM write does.

## `_stage` on reads

`GET records/$ClassRef` and `GET records/$ClassRef/$ID` accept `_stage=draft|live` (default
`draft`). Underscored because bare `stage` is SilverStripe's own reserved staging query param,
consumed by the Versioned middleware **before any controller runs** — using it here would
collide with framework behavior outside this module's control.

For unversioned classes, `_stage` is a no-op (`RecordsHandler::withStage()` short-circuits).

## Generic `/api` writes are stage-unaware

Colymba's own CRUD surface has no concept of `_stage` — a write lands on whatever the
**current stage** is at request time. For a typical front-end request that's **LIVE**, meaning
a colymba write is immediately public (verified on a live testbed). Use this module's batch or
compositions endpoints when you need explicit, predictable draft-first publish semantics.

## Publish modes

Three modes, used by batch ops and (with a restriction — see below) compositions:

| Mode | Effect |
|---|---|
| `none` | Leave on draft. This is the default for every write in this module — an invisible-content state is explicit and visible in the response's `stage` block, never accidental |
| `single` | `publishSingle()` |
| `recursive` | `publishRecursive()` |

Applied via `PublishOrchestrator`, the single place every stage transition in the module goes
through — publish/unpublish/archive/delete all route through it rather than duck-typing
`hasMethod('publishSingle')` at each call site, so "is this record publishable" can't diverge
between call sites. No-op for `none` and for unversioned classes.

## `publishRecursive()` does not cascade to elements

**A page's `publishRecursive()` does not cascade into owned elemental blocks in SS6.** This is
the specific gap `CompositionService::publishAll()` exists to close: a `recursive` composition
publish explicitly publishes the elemental area, then every written element (and its children)
individually via `PublishOrchestrator::publish($record, 'single')`, and only then calls
`page->publishRecursive()`. Relying on the page-level cascade alone would leave elements
stranded on draft behind a live page.

## Stage actions

`POST records/$ClassRef/$ID/{publish|unpublish|archive}`:

| Action | Effect |
|---|---|
| `publish` | `publishSingle()`, or `publishRecursive()` with `{"recursive": true}` in the body |
| `unpublish` | Removes from live, keeps draft (`doUnpublish()`) |
| `archive` | Removes from both stages, recoverable via version history (`doArchive()`) |

`unpublish`/`archive` raise `400 PAYLOAD_INVALID` if called on an unversioned class
(`assertVersioned()`). `publish` does **not** — `PublishOrchestrator::publish()` silently no-ops
for a non-versioned record (same as `mode: "none"`) and the request still returns `200` with no
state change, rather than erroring.

## Composition-level publish restriction

A composition's **top-level** `publish` accepts only `none` or `recursive` — never `single`. A
composition is inherently multi-record (page + area + elements + children); "publish just one
record" has no well-defined meaning at the whole-composition level and would leave the rest on
draft behind a live page. `single` still applies at the per-record level: individual batch
`op`s, and per-element `publish` inside a composition's `elements[]` (though composition
elements are always internally written `publish: "none"` and published via the composition's own
explicit pass — see [Page compositions](08_page-compositions.md#publish)).

## Delete modes and stage

See [Batch operations](07_batch-operations.md#delete-modes) — for a **versioned** record,
`archive` (default) is recoverable via version history, `unpublish` removes it from live only.
On an **unversioned** record, every mode (`archive`, `unpublish`, or `hard`) converges on a real,
non-recoverable delete.
