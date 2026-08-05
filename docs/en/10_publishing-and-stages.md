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

**Reading the default draft stage once it diverges from live requires `VIEW_DRAFT_CONTENT`** —
a core `silverstripe/versioned` permission this module doesn't grant on its own. A service
account with only `CONTENT_API_ACCESS` can write a draft-only record but then can't read it
back until that permission is also granted. See
[Security model](04_security-model.md#service-account-permissions).

## Generic `/api` writes are stage-unaware

Colymba's own CRUD surface has no concept of `_stage` — a write lands on whatever the
**current stage** is at request time. For a typical front-end request that's **LIVE**, meaning
a colymba write is immediately public (verified on a live testbed). Use this module's batch or
compositions endpoints when you need explicit, predictable draft-first publish semantics.

## Publish modes

Four modes, used by batch ops and (with a restriction — see below) compositions:

| Mode | Effect |
|---|---|
| `none` | Leave on draft. This is the default for every write in this module — an invisible-content state is explicit and visible in the response's `stage` block, never accidental |
| `single` | `publishSingle()` |
| `recursive` | `publishRecursive()` |
| `subtree` | `publishSingle()`, then every draft `Hierarchy` tree child, depth-first (see below) |

Applied via `PublishOrchestrator`, the single place every stage transition in the module goes
through — publish/unpublish/archive/delete all route through it rather than duck-typing
`hasMethod('publishSingle')` at each call site, so "is this record publishable" can't diverge
between call sites. No-op for `none` and for unversioned classes. `subtree` walks tree children
only for a class that carries the `Hierarchy` extension (`SiteTree` and its subclasses) — for
anything else it's equivalent to `single`.

## What actually needs an explicit publish call

None of `publishRecursive()`, `subtree`, or any other mode here cascades to *everything* a page
might own. Three distinct things a caller needs to reason about separately (confirmed the hard
way during a real IA restructure — see #71):

1. **`SiteTree` tree children never cascade from a parent's publish, in any mode except
   `subtree`.** `publishRecursive()` is for owned Elemental relations only (see below) — it does
   **not** walk `Hierarchy` children. A 30-page subtree needing to go live needed 30 individual
   `single` publish calls before `subtree` existed to do exactly that walk. `recursive` still
   does not do this — use `subtree` when the goal is "this page and its whole draft tree."
2. **Owned Elemental compositions need `CompositionService::publishAll()`, not a bare
   `publishRecursive()`** — see the next section.
3. **Arbitrary has_many/has_one relations outside both of the above never cascade, in any
   mode, ever** — e.g. `Dynamic\FlexSlider\Model\SlideImage` as a page's hero image relation.
   Confirmed live: a landing page went fully live with a blank hero because its `SlideImage` was
   created via a plain draft `write()` and nothing later explicitly published it, even after the
   owning page went live through every mode above. Identify every such relation on a page being
   published and publish it explicitly — this module has no generic mechanism for it, by design
   (a generic "publish every relation" pass could not distinguish an intentionally-draft related
   record from an accidentally-orphaned one).

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
| `publish` | `publishSingle()` by default. `{"mode": "recursive"}` or `{"mode": "subtree"}` in the body selects the matching [publish mode](#publish-modes) — `{"recursive": true}` remains supported as a legacy shorthand for `mode: "recursive"`, ignored when `mode` is present |
| `unpublish` | Removes from live, keeps draft (`doUnpublish()`) — see the safety guard below |
| `archive` | Removes from both stages, recoverable via version history (`doArchive()`) — same guard |

`unpublish`/`archive` raise `400 PAYLOAD_INVALID` if called on an unversioned class
(`assertVersioned()`). `publish` does **not** — `PublishOrchestrator::publish()` silently no-ops
for a non-versioned record (same as `mode: "none"`) and the request still returns `200` with no
state change, rather than erroring.

## Unpublishing or archiving a `Hierarchy` record: the descendant-cascade guard

**Unpublishing (or archiving) a page with any live tree children silently removed all of them
too, recursively — not just the target page** — confirmed live during a real IA restructure
(#71). The mechanism: SilverStripe's `SiteTree::onBeforeDelete()` cascades to
`AllChildren()` and deletes each of them whenever `SiteTree.enforce_strict_hierarchy` is enabled
(the framework's own default, on every project unless explicitly turned off). `doUnpublish()`
deletes the record from the **LIVE** stage internally, so that cascade fires against whatever
`Hierarchy` children the record currently has on live — every one of them, unconditionally.

**This is broader than "children that were reparented in draft."** An earlier version of this
guard only refused when a live child's draft parent had diverged from live (matching how the
original bug was first diagnosed — a wrapper's children *had* been reparented in draft ahead of
the restructure). That undercounts the real risk: a live child that was never touched at all is
cascade-deleted exactly the same way, the moment its parent is unpublished — proven by a test
written to confirm the narrower guard was sufficient, which failed. The real condition is simply
*does this record have any live `Hierarchy` children at all*, independent of their draft state.

`unpublish()` (and `delete()` with `mode: "unpublish"`, routing through the same path) now
refuses whenever the record has **any** live descendants, failing with `409
UNPUBLISH_STRANDS_DESCENDANTS` and the affected record ids, instead of silently cascading.
`archive()` (and `delete()` with `mode: "archive"`) shares the same guard, checked against
**both** stages — `doArchive()` calls `doUnpublish()` internally (the live-stage risk above),
then also deletes the draft-stage row directly, an equally cascading delete against whatever
`Hierarchy` children currently exist in draft.

**Fix the caller, not the guard**: if this is a restructure, publish (or move) every live
descendant to its new home first — a `subtree` publish on the new parent covers this in one
call — *then* unpublish/archive the old wrapper, never the reverse. If the cascade is genuinely
intended (retiring a whole section on purpose), pass `{"force": true}` (the stage action's
request body, or the batch delete op's `force` field) to bypass the guard and accept it
explicitly.

The guard only applies to classes carrying the `Hierarchy` extension — a plain versioned
`DataObject` with no tree concept is unaffected (nothing to cascade to).

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
