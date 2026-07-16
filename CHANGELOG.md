# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- `sake tasks:SetupContentApiServiceAccount --group="X"` (add `--populate` too if needed) —
  idempotently provisions a service-account Group with `CONTENT_API_ACCESS` +
  `VIEW_DRAFT_CONTENT` always, `CONTENT_API_POPULATE` with `--populate` (#42).

### Docs
- Documented that a service account needs `VIEW_DRAFT_CONTENT` alongside
  `CONTENT_API_ACCESS` to read back its own draft-only writes (#42).

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

[1.3.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/dynamic/silverstripe-content-api/releases/tag/1.0.0
