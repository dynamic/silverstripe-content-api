# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- **many_many `through` support**: a many_many relation backed by an explicit join DataObject
  (`['through' => JoinClass, 'from' => ..., 'to' => ...]`, e.g. a `ProductSizeGTINProduct` join
  carrying `IsCurrent`/`SortOrder`) now round-trips its extra join data the same way a classic
  `many_many_extraFields` relation does: `RecordSerializer` emits `{"id", "extraFields"}` per
  item (reading the join object's own `$db` fields via `getJoin()`), and `schema/$ClassRef`
  advertises the field names under the relation's new `extraFields` key — for both the through
  and classic case, which previously went unreported entirely.
- Schema honesty flags: `api_computed_fields`/`api_import_owned_fields` per-class config marks
  fields that accept a write but then silently overwrite it — recomputed by the model itself
  (e.g. an `onBeforeWrite` trap) or owned by an external feed. Surfaced in `schema/$ClassRef` as
  `computed`/`importOwned` (+ optional `note`) on the field entry. Advisory only — `writable` is
  unaffected; pair with `api_protected_fields` to reject the write outright.

### Fixed
- `WriteApplicator` and `SchemaService` resolved a many_many `through` relation's target class
  by reading the config's `to` value directly as if it were a class name — it's actually the
  *name* of a has_one on the join class (framework
  `DataObjectSchema::parseManyManyComponent()`). A through relation with a `to` value that
  didn't happen to collide with a real class name (the common case) made every write to it
  throw `ReflectionException: Class "..." does not exist`, and its schema entry reported a
  bogus `class`. Both now resolve the real target via
  `DataObject::getSchema()->manyManyComponent()`.

## [1.4.0] - 2026-07-17

### Added
- `sake tasks:SetupContentApiServiceAccount --group="X"` (add `--populate` too if needed) —
  idempotently provisions a service-account Group with `CONTENT_API_ACCESS` +
  `VIEW_DRAFT_CONTENT` always, `CONTENT_API_POPULATE` with `--populate` (#42).

### Fixed
- Archive was gated through the same check as publish/unpublish (`canEdit()`), so any
  member/class granted the `action` verb could silently archive without `canDelete()`
  (#45). `RecordActionsHandler::recordAction()` now computes the verb per action —
  archive maps to `delete` (`canDelete()` at record level, the class's `delete` grant at
  class level); publish/unpublish stay on `action`/`canEdit()`.
- A multirelational polymorphic has_one's `{Name}Relation` disambiguator column (the
  composite field SilverStripe uses to tell which reciprocal has_many a record belongs
  to) was unguarded — a client could write an arbitrary string into it via batch upsert,
  and it leaked in schema introspection and GET responses (#34). Now guarded the same
  way as the existing `{Name}Class` companion column: rejected as `READONLY_FIELD` on
  write, excluded from schema and flat-fields-map responses. `RecordSerializer` also now
  excludes `{Name}Class`/`{Name}Relation` for the plain (non-multirelational) polymorphic
  form, a pre-existing leak there too.

### Docs
- Documented that a service account needs `VIEW_DRAFT_CONTENT` alongside
  `CONTENT_API_ACCESS` to read back its own draft-only writes (#42).
- Comprehensive `docs/en/` reference buildout (16 pages: installation through
  architecture/testing); `README.md` trimmed to a scannable entry point linking into it
  (#44).

## [1.3.0] - 2026-07-16

Found and fixed while recreating a real Essentials homepage end-to-end through the
content API immediately after 1.2.0.

### Added
- `filePath` as an alternative to `base64` on `content_asset_upload`'s spec (#39). Lets an
  MCP client resolve a local file path itself (reading and base64-encoding it before the
  request is sent) instead of requiring the caller to reproduce the entire base64 payload
  as literal text — the companion `dynamic/silverstripe-content-api-mcp` release
  implements the client-side resolution.
- Restored a `relations` field description on `content_batch`/`content_compose_page`'s
  spec documenting the polymorphic has_one `{"class", "id"|"externalId"}` hint
  requirement — previously only ever patched into the MCP repo's bundled spec copy, never
  fed back here, so a spec re-sync had silently dropped it downstream.

### Fixed
- `CompositionService::publishAll()` called `publishSingle()` unconditionally on the area,
  every top-level element, and every has_many child (#37). Any child model that isn't
  Versioned — e.g. `Dynamic\Elements\StatCounters\Model\StatCounter`, a plain `DataObject`
  and real has_many child of the stock `ElementStatCounters` block — crashed the whole
  composition with a `BadMethodCallException`. Now routes through
  `PublishOrchestrator::publish()` (mode `single`), the same "is this record publishable"
  check already used everywhere else on this surface, instead of a second, divergent
  duck-typed check.

## [1.2.0] - 2026-07-15

Security- and correctness-focused release closing every issue found during a full
self-audit of branch `1` (#20–#26), plus the fail-open permission-context bug and
composition write-path authz gaps from the immediately preceding 1.1.0 line
(#18, #27, #19). No endpoint routes, request/response envelope shapes, or config keys
changed — this is a drop-in upgrade from `1.x-dev`/1.1.0 for any consumer already on
branch `1`.

### Fixed

- **List reads leaked hidden-record existence and could silently short-page** (#20).
  `GET records/$ClassRef` computed `meta.total` and applied pagination before
  `canView` filtering — a caller could infer the existence/count of records they
  couldn't view, and a page could come back shorter than requested with no way to
  page around the gap. Visibility is now resolved before total/pagination, bounded to
  the requested page size rather than materializing the whole visible set.
- **Exception messages leaked outside dev/test environments** (#21). Four call sites
  (`CompositionService` page/child writes, `PageHandler` conversion, `LinkTransformer`
  link writes) embedded raw `ValidationException` text in `all`-environment responses,
  bypassing the controller's intended dev/test-only gate. All now route through a
  shared, structured `ApiError::fromValidation()` mapping.
- **Two silent error swallows made misconfiguration indistinguishable from empty
  state** (#22). A broken has_many/many_many relation read and a
  `WriteGuardExtension` payload re-encode failure both previously degraded silently;
  both now log (deduped per relation/request) and the re-encode failure fails the
  request loudly rather than falling back to the unfiltered payload.
- **A polymorphic has_one's write path had three related gaps**: duplicated
  class-hint resolution logic between `WriteApplicator` and `PermissionPolicy` (#24,
  now unified in `ClassRegistry::resolvePolymorphicHint()`); the companion
  `{Name}Class` column wasn't independently allowlist-gated, and could be set
  directly with no `ClassRegistry` validation at all (#25); and colymba's native
  `/api/$Model` surface had no working way to set a polymorphic has_one at all,
  since colymba's deserializer has no concept of the `{"class","id"}` payload shape
  (#23, now translated into the raw columns colymba already understands).
- **GET responses for a polymorphic has_one pointing at an unregistered class** now
  omit the `"class"` key (with a logged warning) instead of emitting a misleading
  `null` or leaking the model's internal FQCN (#26).
- Composition writes conflated server-derived fields (e.g. an element's `ParentID`)
  with user input, and composition children had no class-level ACL at all
  (module #27, #19) — fixed via a dedicated trusted `internalFields` write channel.
- A fail-open bug in `PermissionPolicy::buildCreateContext()` (module #18) and a
  polymorphic has_one create-path crash (module #17) from the 1.1.0 line.

### Added

- `Dynamic\Essentials\Service\ColorTokenResolver` delegation — the `$palette()`/
  `$button()` color-token algorithm is now shared with
  `dynamic/silverstripe-essentials-tools` instead of duplicated.

## [1.1.0] - 2026-07-10

Rearchitected onto `colymba/silverstripe-restfulapi` for token auth and generic CRUD
(`/api`), with this module's own `/content-api/v1` surface layered on top for
stage-aware reads, batch operations, atomic page compositions, and asset ingestion.
See the README's "Upgrading from 1.0.x" section for the token/endpoint migration.

## [1.0.0] - 2026-07-10

Initial release: token auth, class registry, read/write CRUD, publish orchestration,
batch operations, atomic page compositions, asset upload/read, schema introspection,
color tokens, and apply-template.

[1.4.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.3.0...1.4.0
[1.3.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/dynamic/silverstripe-content-api/releases/tag/1.0.0
