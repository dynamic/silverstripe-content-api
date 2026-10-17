# Page compositions

`POST compositions/page` writes one page's full composition — page, elemental area, elements,
children, assets — in a single atomic request. This is the API's replacement for the
Populate-fixtures + attach-task pipeline; see
[Migrating from fixtures](13_migrating-from-fixtures.md).

Population-domain endpoint: requires `dnadesign/silverstripe-elemental` (`501
FEATURE_UNAVAILABLE` otherwise), `CONTENT_API_POPULATE`, and passes
[environment gating](04_security-model.md#environment-gating).

```json
{
  "page": { "match": { "urlSegment": "about" },
            "createIfMissing": { "title": "About", "parentId": 0, "className": "BlockPage" },
            "convertTo": "BlockPage", "fields": { "MetaDescription": "…" } },
  "publish": "recursive",
  "prune": { "enabled": true, "scope": "managed" },
  "assets": [ { "ref": "heroImg", "externalId": "about-hero", "folder": "about",
                "filename": "hero.jpg", "base64": "…" } ],
  "elements": [
    { "class": "ElementCard", "externalId": "about-card-1",
      "fields": { "Title": "Who We Are", "Image": { "$ref": "heroImg" } },
      "children": { "Panels": [ { "externalId": "about-card-1-p1", "fields": { "Title": "…" } } ] } }
  ]
}
```

Atomic: any failure rolls the database writes back. Assets are written draft-only inside the
transaction and published after it commits (the recursive publish leaves them alone), so a
composition that overwrites a published file and then fails keeps that file's published bytes.
New bytes written to the asset store for a failed run stay there unreferenced. Overwriting a
file that has never been published is still destructive on failure: with no Live copy to keep
them, its previous bytes are replaced in the draft write itself. An asset whose post-commit
publish fails is reported in that asset's `warnings` and left out of `published`; the rest of
the composition stands. A retry with the
default `conflict: overwrite` reuses the existing file record (and so needs `update` on the asset
class, see [Assets](09_assets.md#authorization)); `rename` always writes a new record.

## `page`

| Key | Notes |
|---|---|
| `match` | **Required.** `{"id"}`, `{"urlSegment"}`, or `{"externalId"}`. `urlSegment` matching more than one page is `409 MULTIPLE_MATCHES`. A `urlSegment` is normalised the way SiteTree stores one (`About Us` → `about-us`) before matching and creating, so a re-run matches the page the first run created; a `createIfMissing` segment SiteTree would still rewrite (taken, or reserved like `admin`) is `409 URLSEGMENT_COLLISION`, match by `externalId` instead |
| `createIfMissing` | `{title, parentId, className?}`. Required if `match` finds nothing (`404 NOT_FOUND` otherwise) |
| `convertTo` | Short class ref; changes the page's class via `newClassInstance()` if it differs. No-op if already that class. Needs the bare `convert` verb on both the current and the target class (see [`POST pages/$ID/convert`](05_endpoint-reference.md#post-pagesidconvert)) |
| `force` | Required `true` to convert the site home page (`403 HOMEPAGE_CONVERSION_FORBIDDEN` otherwise) |
| `areaRelation` | Default `"ElementalArea"`. Use `"ElementalHomePage"` for HomePage-style page types. This is the one documented location — a top-level `areaRelation` (sibling to `page`) is also accepted, as a defensive fallback for a caller reaching this endpoint directly over HTTP rather than through the MCP tool schema, but `page.areaRelation` always wins if both are given, and a mismatch between the two is reported as a response warning |
| `elementsRelation` | Default `"Elements"`. The area's has_many relation to its child elements — only needed for a custom area class that names it differently |
| `fields` | Sparse page field updates — no populate-style whole-field-map copy |

Composing onto a matched page needs the page class `update` verb plus `canEdit()`, with or
without `fields`. `createIfMissing` needs the page class `create` verb plus `canCreate()`; a
`parentId` must name an existing page (`404 NOT_FOUND` otherwise) and reaches `canCreate()`'s
context as `Parent`. See [Security model](04_security-model.md#composition-and-asset-paths). If the elemental area relation doesn't exist on the resolved page type, the
request fails with `400 PAYLOAD_INVALID` naming the missing relation.

## `elements`

Each entry upserts by **required** `externalId` (the idempotency/prune key) — omitting it is
`400 PAYLOAD_INVALID`.

| Key | Notes |
|---|---|
| `ref` | Per-request alias, resolvable via `{"$ref": "..."}` elsewhere in the **same request** |
| `class` | Short ref; must resolve to a `BaseElement` subclass |
| `externalId` | Required |
| `fields` | Sparse — see [Write payloads](06_write-payloads.md) |
| `relations` | Same shape as batch `relations` |
| `children` | `{ hasManyRelationName: [{externalId, fields, class?}, ...] }` |

**Array order sets `Sort`** unless `fields.Sort` is explicit — the Nth element (1-indexed) gets
`Sort: N` by default. `Sort` is written through the trusted internal channel only as a
*default*; a caller-supplied `fields.Sort` is still subject to the normal `api_writable_fields`
allowlist like any other content field. `ParentID` (the area FK) is **always** server-derived —
it is never eligible for `api_writable_fields`, since that would also expose it on the
untrusted colymba PUT surface (see [Security model](04_security-model.md#the-trusted-internal-fields-channel)).

Element writes upsert with `publish: "none"` internally regardless of the request's top-level
`publish` — the composition's own publish pass (below) handles publishing explicitly.

An element matched by `externalId` that already belongs to a **different** page than the one
just matched by `page.match` is `409 CROSS_PAGE_REPARENT`, not a silent reparent — `externalId`
is a global, site-wide lookup by design, so a wrong `page.match.id`/`urlSegment`/`externalId`
resolving to the wrong page would otherwise force every colliding `externalId` in the payload
onto that unrelated page with no error at all. This check only fires for composition's own
server-derived `ParentID` assignment; an explicit client `fields.ParentID` write via batch/
upsert/update is unaffected and stays governed solely by the `ELEMENT_NOT_ALLOWED_ON_PAGE` check
below (#201).

`class` must also be one of the target page's **allowed** element types — Elemental's own
per-page-type `allowed_elements`/`disallowed_elements` config (`ElementalAreasExtension::
getElementalTypes()`, the same check the CMS admin's own "add element" picker uses) is enforced
here too, not just in the CMS. An element type the page doesn't permit is `422
ELEMENT_NOT_ALLOWED_ON_PAGE`, with the message listing the page's actual allowed types. This is
enforced once, in `RecordWriter`, so it applies identically whether the element arrives via
composition or a plain batch/upsert/update `ParentID` write — and only when placement actually
changes: re-POSTing the same already-composed element after its type becomes disallowed still
succeeds as a plain edit, matching Elemental's own gate (which has no equivalent for "keep
editing existing content," only for a new placement) (#64). An `ElementalArea`-type has_one FK
(e.g. a page's own area relation) is never directly client-writable at all, on any surface,
including colymba `/api` — only a `BaseElement`'s own `Parent` relation to its area is exempt,
since that's the one FK this check inspects. Not covered by this check itself, though: the
colymba `/api` surface can still create/attach an element via its own `ParentID` (a `BaseElement`'s
FK is exempt from the guard above by design, and that surface never routes through this
placement check at all), and attaching an *existing* element via `ElementalArea.Elements` if a
project opts that relation into `api_writable_relations` — both pre-existing gaps in how those
surfaces relate to this module's write pipeline, tracked separately.

### `$ref` resolution

`{"$ref": "heroImg"}` anywhere inside `fields`/`relations` (nested, deep) resolves to
`{"id": <resolved-record-id>}` once the aliased asset/element has been written. Elements are
processed with a deferred-retry queue: an element referencing a `$ref` not yet resolved is
retried after the others process, so `$ref` order within the request doesn't matter. When a
pass makes no progress, an alias no stuck element declares (via its own `ref`) is `422
UNRESOLVED_REF`, however many elements want it. `422 CIRCULAR_REF` means every alias still
wanted is declared by a stuck element, e.g. two elements referencing each other or one
referencing its own `ref`.

### `children`

Owned records written under the element's aggregate, attached via the has_many relation (which
sets the FK). They enforce the **same class-level ACL and `canCreate()`/`canEdit()` gate** as
any other write — `CONTENT_API_POPULATE` alone is not sufficient; the child class also needs
its own `api_access` for the verb. A child's `externalId` lookup is scoped to its **owning
element's own relation list**: a composition can never adopt or re-parent a child that currently
belongs to a different element, even on a global external-id collision — that scenario is
treated as not-found (a new child is created) rather than a silent re-parent. A `class` override
on a child entry must be a subclass of the relation's declared type
(`400 PAYLOAD_INVALID` otherwise).

Children have no ordering default: array order does **not** set a sort column, because child
classes share none. Pass the class's own sort field (e.g. `SortOrder`) in `fields`. Without
`prune`, children are append-and-update only: a child left out of the list stays attached. With
`prune` enabled, see below.

## `assets`

Each entry: `ref` (optional alias), plus the same shape as [Assets](09_assets.md) —
`filename`/`base64` required, `folder`/`title`/`externalId`/`conflict`/`publish` optional. A
`base64`-less entry is `400 PAYLOAD_INVALID`. Entries are authorized exactly like `POST assets`
(see [Assets](09_assets.md#authorization)). An entry without its own `publish` follows the
composition: draft-only under `publish: "none"`, published otherwise. A draft-only fallback for
a class without `action` is reported in that entry's `warnings`.

## `prune`

```json
{ "enabled": true, "scope": "managed" }
```

Archives elements in the area that the payload no longer describes — `doArchive()`, so
recoverable. `scope: "managed"` (default) only considers elements carrying an external id;
hand-authored CMS content (no external id) is invisible to prune and never touched. `scope:
"all"` also considers external-id-less elements. Kept elements are anything in the payload's
`elements[]` list by `externalId`; everything else in the area matching the scope is pruned.
Each pruned element needs the `delete` verb on its class plus `canDelete()`; every candidate is
checked before any is archived, so one refusal leaves the area untouched.

Prune also covers each element's `children`. For every relation an element entry names under
`children`, attached children the request did not write are removed by the same scope rule:
archived when the child class is versioned, deleted otherwise. An empty list removes every
child under that relation. A relation the entry does not name is left alone, so omit `children`
(or the relation) to keep what is there. Every key under `children` must be a has_many of the
element, even with an empty list (`422 UNKNOWN_RELATION` otherwise). Two entries upserting the
same element keep each other's children. Each removed
child needs `delete` on its class plus `canDelete()`, and is reported in `pruned` with its
`relation` and owning `element` id. A refusal rolls back the whole composition.

## `publish`

Top-level, accepts only `"none"` or `"recursive"` — **not** `"single"`. A composition is
inherently multi-record (page + area + elements + children); "publish just one record" has no
well-defined meaning at this level and would leave the rest on draft behind a live page.
`"single"` remains valid at the per-record level: individual batch `op`s, and per-element
`publish` inside `elements[]` (though composition elements are always written `publish: "none"`
internally and published via the composition's own explicit pass instead).

`recursive` routes the area, every written element (and its children), and the page itself
through `PublishOrchestrator::publishOwnedTree()` (the same primitive the `owns` publish mode
uses, see [Publishing & stages](10_publishing-and-stages.md#publish-modes)) — **a page's own
`publishRecursive()` does not cascade into owned elemental blocks**, so the composition does this
explicitly rather than relying on that cascade. The walk covers the page's whole `$owns` tree,
so a draft element already in an owned area (one an editor left unpublished, say) goes live
too, not only what this request wrote; the response's `published` list names every record
published. Every one of area/elements/children is
authorization-checked (class `action` verb + `canPublish()`) before anything is written — #119/#168: a class
granting `create`/`update` but withholding `action` now refuses the whole composition publish
with `403 FORBIDDEN_CLASS`, where it previously published silently. Not every has_many child
model is Versioned (e.g. Essentials' `StatCounter` is a plain DataObject) — `publishOwnedTree()`
silently drops anything unversioned from its target set rather than erroring.

## Response

```json
{
  "page": { "id": 12, ..., "operation": "matched|created|converted|updated" },
  "area": { "id": 34, "created": false },
  "elements": [ { "index": 0, "ref": "heroImg", "externalId": "about-card-1", "id": 56,
                  "status": "created", "warnings": [], "children": [...] } ],
  "assets": [ { "index": 0, "ref": "heroImg", "id": 78, "filename": "...", "status": "created" } ],
  "pruned": [ { "id": 90, "externalId": "old-card", "className": "ElementCard" },
              { "id": 91, "externalId": "old-panel", "className": "Panel",
                "relation": "Panels", "element": 56 } ],
  "published": [ { "id": 56, "className": "ElementCard" }, { "id": 12, "className": "Page" } ]
}
```

`assets` and `pruned` are only present when the request included them; `published` only under
`publish: "recursive"`, listing every record the publish touched with the page last.
