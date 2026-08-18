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

## Draft/live parity

`GET records/$ClassRef/$ID/parity` (#120) answers "does this record, and everything it
`$owns`, match between draft and live, and where do they differ" — a read this module didn't
previously have, that every project consuming it ended up hand-rolling per install
(`DraftLiveParityTask`, ~230 lines on `sheboygan-youth-sailing-installer`, explicitly documented
there as "the canonical step after any go-live publish").

Two independent checks, both against the same draft read:

- **Root fields** — a configurable set (`Title`/`ParentID`/`ClassName`/`ShowInMenus`/
  `URLSegment`/`Sort` by default, filtered to whichever the class actually declares), compared
  draft vs live. The record not existing on live at all is reported as `liveExists: false` — a
  legitimate state (a placeholder, or a page never published), never treated as a failure by
  itself.
- **Owned tree** (`?include=owned`, the default — `?include=none` skips it; `?depth=N` caps how
  far it recurses, and rejects a non-numeric value rather than silently disabling the whole walk)
  — every record this one `$owns`, recursively, via `Dynamic\ContentApi\Verify\OwnedTreeWalker`
  (the module's first `$owns` walker, built for this endpoint; no class in `src/` declares `$owns`
  itself). The `owns` publish mode (see [Publish modes](#publish-modes)) now walks the same
  `$owns` chain to publish it, so Elemental's page→area→elements cascade *is* `$owns`-driven for
  that part — only an element's own has_many children still aren't (`BaseElement` declares no
  `$owns`; see [Publish modes](#publish-modes) for how the publish side works around that with
  `$additional`). Reports each owned descendant's live/draft status and
  depth, **not** a field-level diff of each one (only the root gets that). Walks *through* an
  unversioned intermediate record without reporting it (it has no draft/live state of its own),
  matching `RecursivePublishable`'s real recursion behavior rather than pruning the whole branch
  there; a record reachable via more than one owned path (a diamond — the same shared `File`
  owned both directly by a page and by one of its elements is a realistic shape) is reported at
  its shallowest reachable depth.

An owned descendant's live status disagreeing with the root's own is a genuine mismatch
(`ok: false`) — root live + descendant draft-only is the exact bug class this endpoint primarily
exists to catch (a live page 404ing because something it owns, several levels down, never got
published — caught in a real production restructure only because a hand-rolled fingerprint task
happened to print the two contradicting lines adjacent to each other and a human happened to read
them together); root NOT live + descendant live is the mirror image — stranded content the root's
own publish history should have carried, or never published in the first place. Root and
descendant agreeing, live or not, is always consistent — an owned tree that's uniformly draft
because the whole branch was never published is not a problem.

Both the root and every owned record are read by their **true base class**, not the requested (or
concrete, for an owned record) class — a record converted to a different class on draft only
(`POST pages/$ID/convert` with `publish: "none"`) has a live row whose `ClassName` is still the
old one, and querying through the new class's own (narrower) subclass set would otherwise
silently fail to find it — a subclass's own subclass set never includes its ancestor. That would
report a genuinely-live, genuinely-divergent record as `liveExists: false` / `ok: true`; the
actual class difference now surfaces correctly as an ordinary `ClassName` field mismatch instead.

Response carries both a machine-readable structure (`fields`, `owned`, `liveExists`, `ok`) and a
flat `report: [{label, ok, message}]` list, so a project can drop its own version of
`DraftLiveParityTask::report()` entirely. `400 PAYLOAD_INVALID` for a non-Versioned class —
there's nothing to compare. Authorization follows the same check-everything-before-emitting-
anything precedent as `subtree` publish above: every owned class/record is authorized before any
part of the report is built, so a forbidden branch fails the whole request rather than silently
disappearing from the output.

## Generic `/api` writes are stage-unaware

Colymba's own CRUD surface has no concept of `_stage` — a write lands on whatever the
**current stage** is at request time. For a typical front-end request that's **LIVE**, meaning
a colymba write is immediately public (verified on a live testbed). Use this module's batch or
compositions endpoints when you need explicit, predictable draft-first publish semantics.

## Publish modes

Five modes, used by batch ops and (with a restriction — see below) compositions:

| Mode | Effect |
|---|---|
| `none` | Leave on draft. This is the default for every write in this module — an invisible-content state is explicit and visible in the response's `stage` block, never accidental |
| `single` | `publishSingle()` |
| `recursive` | `publishRecursive()` |
| `subtree` | `publishSingle()`, then every draft `Hierarchy` tree child, depth-first (see below) |
| `owns` | `publishSingle()` on every `$owns`-reachable descendant, then the record itself (see below) |

Applied via `PublishOrchestrator`, the single place every stage transition in the module goes
through — publish/unpublish/archive/delete all route through it rather than duck-typing
`hasMethod('publishSingle')` at each call site, so "is this record publishable" can't diverge
between call sites. No-op for `none` and for unversioned classes. `subtree` walks tree children
only for a class that carries the `Hierarchy` extension (`SiteTree` and its subclasses) — for
anything else it's equivalent to `single`. `owns` walks the class's own `$owns` config (via
`OwnedTreeWalker`, the same primitive [draft/live parity](#draftlive-parity) uses) — for a class
with nothing declared there, it's likewise equivalent to `single`.

`subtree` and `owns` both take `dryRun` (see [Publish/unpublish/archive
actions](#publishunpublisharchive-actions) for how to pass it); `subtree` alone also takes
`liveOnly`:

- **Authorization**: every descendant either mode visits is checked (class `action` verb +
  `canEdit()`) — a token scoped to `Page` can't reach a descendant of a subclass whose own
  `api_access` only grants `read`, and a member without CMS access to a specific child page can't
  publish it by publishing an ancestor they can edit instead. The whole walk is checked *before*
  anything is written — a permission gap on descendant #12 refuses the entire call rather than
  leaving descendants #1-11 live with no way to undo it. The *root* record itself is assumed to
  already be authorized by whichever call site is invoking `publish()`. Fixed for every
  root-record call site (#114): `RecordActionsHandler` checks `action` directly;
  `RecordWriter::write()` checks the class-level `action` verb whenever the payload's `publish`
  key isn't `none`; `PageHandler::convert()`/`CompositionService::convertPage()` check the
  *target* class's `action` verb under the same condition, not just the *pre-conversion*
  record's `update`; and `CompositionService::compose()` itself checks the page's own `action`
  verb before `publishAll()`, whether or not `convertTo` was used. `owns`' own descendant
  authorization (#119/#168) closed the last gap in this family: `CompositionService::publishAll()`
  and `PageHandler::applyTemplate()` used to publish a composition's area/elements/children via
  `publish($record, 'single', $member)`, which performs no authorization at all — nothing upstream
  checked the `action` verb for those classes either, since their own writes always pass
  `"publish": "none"` explicitly. Both call sites now route through
  `PublishOrchestrator::publishOwnedTree()` instead, the same authorized primitive `owns` mode
  itself dispatches to.

  **Behavior change, not a compatibility knob.** The walk checks every `$owns`-reachable class,
  not just elements — the blast radius is wider than "element type" alone. `File`/`Image` are
  versioned, so any page or element declaring `private static $owns = ['SomeImageRelation']` (a
  common pattern for image-bearing elements, and the standard Elemental "add an asset" wiring)
  pulls `SilverStripe\Assets\File`/`Image` into the checked set too — and asset classes are the
  ones most likely to be configured `read`/`create`-only in a project's exposure config, since
  publishing an asset was never previously a thing this module authorization-checked. A project
  whose element, area, page, or **owned asset** classes grant `create`/`update` in `api_access`
  but withhold `action` will start getting `403 FORBIDDEN_CLASS` on a composition or
  apply-template call with a publishing mode, where it previously published silently. Grant
  `action` on every affected class, or pass `publish: "none"` if the cascade was never intended to
  publish them.
- **`liveOnly`** (`subtree` only): skip a descendant branch — no publish, no recursing into its
  own children — when it isn't already live. See the resurrection-risk warning below. Meaningless
  for `owns` — an owned-relation graph isn't a `Hierarchy` tree, so "already live" isn't a
  branch-skip signal the same way — and refused with `400 PAYLOAD_INVALID` rather than silently
  ignored if passed with `owns`.
- **`dryRun`**: run the full authorization-checked walk (so the same error a real call would
  throw still surfaces) and return the would-publish set, without calling `publishSingle()` on
  anything.

`liveOnly` is rejected with `400 PAYLOAD_INVALID` on any mode other than `subtree`; `dryRun` on
any mode other than `subtree` or `owns` — neither merely no-ops, since silently ignoring `dryRun`
would perform a real write while the caller reasonably expected a preview.

`owns` additionally accepts a caller-known set of records outside the `$owns` graph itself when
called via `PublishOrchestrator::publishOwnedTree()` directly (not exposed as a request
parameter — `CompositionService`/`PageHandler` use it internally). This matters because
Elemental's own `$owns` chain stops at the area: `ElementalPageExtension` declares
`$owns = ['ElementalArea']` and `ElementalArea` declares `$owns = ['Elements']`, but `BaseElement`
itself declares no `$owns` at all, so an element's own has_many children aren't walk-reachable
unless a project opts in. Both call sites cover those children explicitly rather than relying on
the walk alone, by different routes: `CompositionService::publishAll()` passes the exact records
it just wrote, while `PageHandler::applyTemplate()` can't ask `TemplateApplicator` what it wrote
and instead walks each element's duplicated-relation tree via
`OwnedTreeWalker::walkDuplicates()` (#174).

That walk reproduces what `DataObject::duplicate()` creates rather than reading one config, which
takes two corrections in opposite directions: an **empty** `$cascade_duplicates` on a `Versioned`
record makes the framework fall back to `$owns ∩ (many_many + belongs_to + has_many)`
(`RecursivePublishable::onBeforeDuplicate()`), and a **many_many** entry link-copies existing
records rather than cloning new ones, so its targets pre-date the duplicate and must not be
published on its behalf.

## What actually needs an explicit publish call

None of `publishRecursive()`, `subtree`, or any other mode here cascades to *everything* a page
might own. Three distinct things a caller needs to reason about separately (confirmed the hard
way during a real IA restructure — see #71):

1. **`SiteTree` tree children never cascade from a parent's publish, in any mode except
   `subtree`.** `publishRecursive()` cascades to some owned relations, but **not** `Hierarchy`
   tree children, and — per the next section — not owned Elemental compositions either. A
   30-page subtree needing to go live needed 30 individual `single` publish calls before
   `subtree` existed to do exactly that walk. `recursive` still does not do this — use
   `subtree` when the goal is "this page and its whole draft tree."
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

**A page's `publishRecursive()` does not cascade into owned elemental blocks — confirmed
identical on branches `1` and `2` (#91), not an SS6-specific gap.** This is
the specific gap `CompositionService::publishAll()` exists to close: a `recursive` composition
publish routes the elemental area, every written element (and its children), and the page itself
through `PublishOrchestrator::publishOwnedTree()` (`owns` mode's underlying primitive) — every one
of them authorization-checked before anything is written, then published together. Relying on the
page-level cascade alone would leave elements stranded on draft behind a live page.
`PageHandler::applyTemplate()`'s own `recursive` publish does the same for its area/elements.

## Stage actions

`POST records/$ClassRef/$ID/{publish|unpublish|archive}`:

| Action | Effect |
|---|---|
| `publish` | `publishSingle()` by default. `{"mode": "recursive"}` or `{"mode": "subtree"}` in the body selects the matching [publish mode](#publish-modes) — `{"recursive": true}` remains supported as a legacy shorthand for `mode: "recursive"`, ignored when `mode` is present. `mode: "subtree"` alone also accepts `{"liveOnly": true}` and `{"dryRun": true}` (see [Publish modes](#publish-modes)); both are `400 PAYLOAD_INVALID` on every other mode. `dryRun` responds with `{"data": {"wouldPublish": [...]}, "meta": {"operation": "publishDryRun", "mode": "subtree"}}` instead of the normal response. A real (non-`dryRun`) `subtree` call keeps the normal serialized-record response but adds `meta.published`: the same `[{id, className}, ...]` list, so a `liveOnly` call still reports what was actually touched |
| `unpublish` | Removes from live, keeps draft (`doUnpublish()`) — see the safety guard below. `{"force": true}` also requires the `delete` verb, not just `action` (#80) — see [Security model](04_security-model.md#record-level-gate) |
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

**This recommendation carries its own resurrection risk if publish state is a business
signal, not just a migration artifact (#102).** `subtree` on the new parent publishes *every*
descendant currently on draft, unconditionally — including one a caller deliberately took
offline (e.g. a product/class page pulled because it's no longer offered), with no way to opt
out. If any descendant under the subtree being restructured was intentionally unpublished
rather than merely not-yet-published, a plain `subtree` call puts it back live. Pass
`{"liveOnly": true}` alongside `{"mode": "subtree"}` to publish only descendants that are
*already* live, leaving deliberately-offline ones (and everything under them) untouched — or
`{"dryRun": true}` first to see exactly what a real call would touch before running it for
real.

The guard only applies to `SiteTree` records with `enforce_strict_hierarchy` enabled — the only
combination `SiteTree::onBeforeDelete()`'s cascade actually fires for (#89). A `Hierarchy`-
extended, `Versioned` class that isn't `SiteTree`, or a project that has explicitly set
`SiteTree.enforce_strict_hierarchy: false`, never has this cascade risk in the first place, so
`unpublish`/`archive` on those succeed directly with no need for `force` — passing `force` there
was previously required for a cascade that was never actually going to happen. A plain versioned
`DataObject` with no tree concept is unaffected either way (nothing to cascade to).

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
