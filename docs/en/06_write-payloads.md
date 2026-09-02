# Write payloads

The shape shared by batch ops (`fields`/`relations`/`externalId`/`publish`) and composition
elements. Writes validate before applying — a rejected payload changes nothing (see
[Security model](04_security-model.md#field-level-gate-write-policies) for how field
writability is decided).

```json
{
  "fields": { "Title": "Welcome", "BackgroundColor": "$palette(0)",
              "Image": { "externalId": "home-hero-img" },
              "CtaLink": { "type": "ExternalLink", "url": "/contact", "title": "Contact us" } },
  "relations": { "Staff": { "mode": "add", "items": [ { "externalId": "staff-jane",
                 "extraFields": { "SortOrder": 1 } } ] } },
  "externalId": "home-hero",
  "publish": "none|single|recursive|subtree|owns"
}
```

PascalCase field names round-trip between GET responses and write payloads on both surfaces
(colymba's serializer also emits verbatim names).

## `fields`

Sparse — only keys present in the payload are touched (`WriteApplicator::applyFields()`; the
anti-clobber guarantee). Unknown keys are rejected (`422 UNKNOWN_FIELD`) or warned-and-ignored
depending on `api_unknown_fields`. An Enum/MultiEnum-backed field's value is validated against
its own declared list — see `schema/$ClassRef`'s `values` — and rejected with
`422 INVALID_VALUE` if it isn't in it; a MultiEnum's comma-separated values are each checked
independently.

### has_one values

A non-polymorphic has_one accepts:

| Shape | Meaning |
|---|---|
| `null` | Clear the relation |
| integer, or numeric string | Foreign key id directly |
| `{"id": n}` | Same as a bare id |
| `{"externalId": "..."}` | Resolve via [external id](#external-ids) |
| A link payload (`{"type": ..., ...}`) | See [Link payloads](#link-payloads) |
| `{"$ref": "name"}` inside a composition | Resolve a per-request alias — see [Page compositions](08_page-compositions.md) |

### Polymorphic has_one

A relation declared `'Relation' => DataObject::class` (e.g. core's
`UserForms\EmailRecipient.Form`) has no single target class to infer, so a bare id or
hint-less `{"id"/"externalId"}` is rejected as ambiguous (`400 PAYLOAD_INVALID`). It must carry
an explicit `"class"` hint:

```json
{ "class": "ElementForm", "id": 1431 }
{ "class": "ElementForm", "externalId": "contact-form" }
```

`"class"` is the same short registry ref used elsewhere (e.g. a composition child's `class`
override) — see [`ClassRegistry::resolvePolymorphicHint()`](../../src/Registry/ClassRegistry.php).

GET responses for a polymorphic has_one emit this same `{"id", "class"}` shape (instead of a
bare int) so a read round-trips directly into a write. If the FK is set but the companion
`{Name}Class` column is empty or names an unregistered class, `"class"` is simply omitted from
the read (never a misleading `null`) — writing back without a class hint then correctly fails
with `PAYLOAD_INVALID` rather than silently targeting nothing.

The FK column and its companion `{Name}Class` column are gated **as a pair**: both must
independently pass the writability check. A polymorphic relation's `{Name}Class` column can
never be set as a bare payload key directly (only as a side effect of resolving the relation via
its FK) — that would let a client set an arbitrary raw class name with no registry validation.

**Multirelational polymorphic has_one** (declared `['class' => DataObject::class,
'multirelational' => true]`, when the same polymorphic has_one is shared by more than one
reciprocal has_many) writes and reads identically to the plain-string form above — the
framework normalizes both to the same class string before any of this module's code sees them.
The one difference: this form gets a third physical column, `{Name}Relation`
(`DBPolymorphicRelationAwareForeignKey`'s own composite field — SilverStripe's internal
disambiguator for which reciprocal has_many a record belongs to). Nothing in this module
resolves a value for it, so it's **never** a writable payload key — a write attempt against it
is rejected with the same `READONLY_FIELD` error class a bare `{Name}Class` key gets, though
not identically: unlike `{Name}Class`, there's no trusted-internal-code exception, since no
in-process code in this module has a way to resolve the correct value either. This means a
multirelational has_one is currently only *partially* writable through this API: the has_one
itself (FK + Class) writes and reads correctly, but the record won't show up via the reciprocal
has_many side until `{Name}Relation` is set some other way (e.g. the CMS, or a direct ORM write)
— assigning it via a client-specified relation name isn't yet a feature this API exposes.

This only applies to writes going through this module's own pipeline (single-record writes,
batch, compositions). Colymba's generic `/api` write path applies payload keys directly to DB
columns with no relation-shape handling at all — `WriteGuardExtension` only guards *which*
fields colymba's own deserializer already decided to write; it doesn't add relation resolution
to that surface.

### Link payloads

Requires `silverstripe/linkfield`. On a has_one field targeting a `Link` subclass:

```json
"CtaLink": { "type": "ExternalLink", "url": "/contact", "title": "Contact us", "openInNew": false }
```

| `type` | Fields |
|---|---|
| `ExternalLink` | `url` (site-relative, e.g. `/contact`, is the environment-independent convention) |
| `SiteTreeLink` | `pageId`, `anchor` |
| `EmailLink` | `email` |
| `PhoneLink` | `phone` |
| `FileLink` | `fileId` |

All types accept `title` (→ `LinkText`) and `openInNew`. Sparse and idempotent: if the field
already points at a link of the *same* type, that record is updated in place; a type change
writes a new link record. Owner fields are never set by this transformer — linkfield derives
ownership from the pointing has_one.

A key that's neither in the table above nor `title`/`openInNew` is rejected with
`422 UNKNOWN_FIELD` — including a key that's valid for a *different* `type` (e.g. `fileId` sent
with `"type": "ExternalLink"`), not just one that's unrecognized outright.

### Color tokens

Requires `dynamic/silverstripe-essentials-tools`. On fields listed in `token_fields` (default
`BackgroundColor`, `ButtonColor`):

- `$palette(N)` → the Nth configured background color hex
- `$button(N, Label)` → a JSON button-color-combo blob for background `N` (falls back to that
  background's first combo when `Label` doesn't exist, so shared payloads can use generic
  labels)

An unresolvable token **fails the write** with `422 TOKEN_RESOLUTION_FAILED` — unlike the
Populate-fixtures resolver, which logged and left the literal token string in place. See
[Migrating from fixtures](13_migrating-from-fixtures.md#gotchas-carried-over-and-neutralized).

## `relations` (has_many / many_many)

```json
{ "Staff": { "mode": "add", "items": [ { "externalId": "staff-jane", "extraFields": { "SortOrder": 1 } } ] } }
```

- `mode`: `set` (clear then apply — `removeAll()` first), `add`, or `remove`.
- `items`: each entry is an integer id, `{"id": n}`, or `{"externalId": "..."}`; `extraFields`
  is only meaningful for many_many join-table columns.
- The relation must be listed in the class's `api_writable_relations` — this is a separate
  allowlist from `api_writable_fields` and applies under **both** write policies.
- Unknown relation → `422 UNKNOWN_RELATION`; not in the allowlist → `422 READONLY_FIELD`;
  malformed `mode`/`items` → `400 PAYLOAD_INVALID`. This block is has_many/many_many only — a
  has_one name here (a natural mistake) is rejected the same way, `400 PAYLOAD_INVALID`, before
  the record is written at all: put it under `fields` instead.

A has_many `add`/`set` that attaches an **already-published** record republishes it (when this
operation's own `publish` isn't `none`) — `HasManyList::add()` unconditionally repoints the
related record's foreign key via an ordinary draft write, which would otherwise leave it stranded
`modifiedOnDraft` even though nothing about its own content changed (#202). A related record that
was never published is left exactly as it was — only a record that was already live before this
operation touched it gets put back. A many_many `add` has no equivalent gap: it only writes the
related record at all when it isn't already in the database.

`extraFields` round-trips on read too: a GET response serializes a many_many relation that
carries extra join data as `[{"id", "extraFields"}, ...]` (`RecordSerializer`), not a bare id
array — the same shape `items` accepts on write. Two relation shapes carry extra data:
- **Classic `many_many_extraFields`**: a config map of field name → db type on the *owning*
  class (e.g. `Tags` above).
- **`many_many through`**: a `many_many` entry declared as `['through' => JoinClass, 'from' =>
  ..., 'to' => ...]` — the extra fields are real `$db` columns on the join DataObject itself
  (e.g. a `ProductSizeGTINProduct` join carrying `IsCurrent`/`SortOrder`). Reads and writes use
  the identical `{"id", "extraFields"}` shape either way; `schema/$ClassRef` advertises the
  field names for both cases under the relation's `extraFields` key (see
  [Schema introspection](11_schema-introspection.md)).

## `externalId`

Sets/matches the [external id](#external-ids) column for this write's target record.

## `publish`

`none` (default — leave on draft), `single` (`publishSingle()`), `recursive`
(`publishRecursive()`), `subtree` (record plus every draft `Hierarchy` tree child), or `owns`
(record plus every `$owns`-reachable descendant). See
[Publishing & stages](10_publishing-and-stages.md).

Anything other than `none` requires the class's `action` verb, not just `update`/`create` (#114)
— see [Security model](04_security-model.md#class-level-gate).

## External ids

The API's idempotency key — `ExternalIdResolver` reads/writes the column named by
`external_id_field` (default `FixtureIdentifier`, matching the Populate-fixtures recipe's own
column so previously-populated sites are addressable as-is; see
[Migrating from fixtures](13_migrating-from-fixtures.md)).

Matching is **strict**: zero matches is a miss (`404 NOT_FOUND` when a lookup is required), one
match is a hit, more than one is `409 MULTIPLE_MATCHES` — the API never guesses. A class needs
[`ExternalIdentifierExtension`](02_configuration.md#externalidentifierextension) applied before
external ids work on it (`422 EXTERNAL_ID_UNSUPPORTED` otherwise).

`ext:<external-id>` is also accepted as the `$ID` path segment on read/action endpoints, e.g.
`GET records/ElementCard/ext:home-hero`.
