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

Atomic: any failure rolls the whole request back. Exception: asset binaries are content-addressed
and converge on retry, so a partially-ingested asset from an aborted run is never a problem —
re-POSTing the same payload is safe and idempotent (updates existing records, never duplicates).

## `page`

| Key | Notes |
|---|---|
| `match` | **Required.** `{"id"}`, `{"urlSegment"}`, or `{"externalId"}`. `urlSegment` matching more than one page is `409 MULTIPLE_MATCHES` |
| `createIfMissing` | `{title, parentId, className?}`. Required if `match` finds nothing (`404 NOT_FOUND` otherwise) |
| `convertTo` | Short class ref; changes the page's class via `newClassInstance()` if it differs. No-op if already that class |
| `force` | Required `true` to convert the site home page (`403 HOMEPAGE_CONVERSION_FORBIDDEN` otherwise) |
| `areaRelation` | Default `"ElementalArea"`. Use `"ElementalHomePage"` for HomePage-style page types |
| `elementsRelation` | Default `"Elements"`. The area's has_many relation to its child elements — only needed for a custom area class that names it differently |
| `fields` | Sparse page field updates — no populate-style whole-field-map copy |

The page is created with `CONTENT_API_POPULATE` and the same `canCreate()` context-hydration as
any other write. If the elemental area relation doesn't exist on the resolved page type, the
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

`class` must also be one of the target page's **allowed** element types — Elemental's own
per-page-type `allowed_elements`/`disallowed_elements` config (`ElementalAreasExtension::
getElementalTypes()`, the same check the CMS admin's own "add element" picker uses) is enforced
here too, not just in the CMS. An element type the page doesn't permit is `422
ELEMENT_NOT_ALLOWED_ON_PAGE`, with the message listing the page's actual allowed types. This is
enforced once, in `RecordWriter`, so it applies identically whether the element arrives via
composition or a plain batch/upsert/update `ParentID` write — and only when placement actually
changes: re-POSTing the same already-composed element after its type becomes disallowed still
succeeds as a plain edit, matching Elemental's own gate (which has no equivalent for "keep
editing existing content," only for a new placement) (#64). Not covered by this check at all: the
colymba `/api` generic write surface, and attaching an existing element via
`ElementalArea.Elements` if a project opts that relation into `api_writable_relations` — both are
pre-existing gaps in how those surfaces relate to this module's write pipeline, tracked
separately.

### `$ref` resolution

`{"$ref": "heroImg"}` anywhere inside `fields`/`relations` (nested, deep) resolves to
`{"id": <resolved-record-id>}` once the aliased asset/element has been written. Elements are
processed with a deferred-retry queue: an element referencing a `$ref` not yet resolved is
retried after the others process, so `$ref` order within the request doesn't matter. A `$ref`
that never resolves is `422 UNRESOLVED_REF`; a cycle among only-deferred elements is `422
CIRCULAR_REF`.

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

## `assets`

Each entry: `ref` (optional alias), plus the same shape as [Assets](09_assets.md) —
`filename`/`base64` required, `folder`/`title`/`externalId`/`conflict`/`publish` optional. A
`base64`-less entry is `400 PAYLOAD_INVALID`.

## `prune`

```json
{ "enabled": true, "scope": "managed" }
```

Archives elements in the area that the payload no longer describes — `doArchive()`, so
recoverable. `scope: "managed"` (default) only considers elements carrying an external id;
hand-authored CMS content (no external id) is invisible to prune and never touched. `scope:
"all"` also considers external-id-less elements. Kept elements are anything in the payload's
`elements[]` list by `externalId`; everything else in the area matching the scope is pruned.

## `publish`

Top-level, accepts only `"none"` or `"recursive"` — **not** `"single"`. A composition is
inherently multi-record (page + area + elements + children); "publish just one record" has no
well-defined meaning at this level and would leave the rest on draft behind a live page.
`"single"` remains valid at the per-record level: individual batch `op`s, and per-element
`publish` inside `elements[]` (though composition elements are always written `publish: "none"`
internally and published via the composition's own explicit pass instead).

`recursive` publishes the area, then every written element (and its children) individually via
`PublishOrchestrator::publish($record, 'single')`, then finally `page->publishRecursive()` —
**a page's own `publishRecursive()` does not cascade into owned elemental blocks**, so the
composition does this explicitly rather than relying on that cascade. Not every has_many child
model is Versioned (e.g. Essentials' `StatCounter` is a plain DataObject) — routing every publish
through `PublishOrchestrator` (rather than a duck-typed `hasMethod('publishSingle')` check) keeps
"is this record publishable" answered in exactly one place.

## Response

```json
{
  "page": { "id": 12, ..., "operation": "matched|created|converted|updated" },
  "area": { "id": 34, "created": false },
  "elements": [ { "index": 0, "ref": "heroImg", "externalId": "about-card-1", "id": 56,
                  "status": "created", "warnings": [], "children": [...] } ],
  "assets": [ { "index": 0, "ref": "heroImg", "id": 78, "filename": "...", "status": "created" } ],
  "pruned": [ { "id": 90, "externalId": "old-card", "className": "ElementCard" } ]
}
```

`assets` and `pruned` are only present when the request included them.
