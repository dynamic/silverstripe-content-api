# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- **(#76)** `Dynamic\ContentApi\Security\ContentApiGrantExtension`: grants record-level
  `canView()`/`canEdit()`/`canCreate()`/`canDelete()` to any Member holding
  `CONTENT_API_ACCESS`, so a service account can create/move/reparent/publish/archive records
  without holding `ADMIN` — the app-level grant the module's own docs and
  `ServiceAccountProvisioner` output have always said a project needs but previously left every
  project to write itself. Project-applied via YAML, never auto-applied (same pattern as
  `WriteGuardExtension`/`ExternalIdentifierExtension`). Scoped per-verb to a class's own
  **uninherited** `content_api_access`/`api_access` declaration (new
  `ClassRegistry::ownAccessVerbs()`) — a class that only inherits its ancestor's access never
  gets a grant, closing a real privilege-escalation vector confirmed against a downstream
  project's first-cut implementation (undeclared `Page` subclasses inheriting `DELETE`/`action`
  at the class gate). See [docs/en/04_security-model.md#grant-extension](docs/en/04_security-model.md#grant-extension).
- **(#71)** `subtree` publish mode: publishes a record, then every draft `Hierarchy` tree child
  depth-first. `publishRecursive()` (existing `recursive` mode) does not cascade to `SiteTree`
  children — only owned Elemental relations — so a multi-page subtree previously needed one
  explicit `single` publish call per page; `subtree` does it in one. Equivalent to `single` for
  a non-hierarchical class. Available on `PublishOrchestrator::MODES`, batch op `publish`/
  `defaultPublish`, and `content_page_convert`'s `publish` field. Spec bumped to `v1.5`.
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
- **(#61)** `ColorTokenTransformer::supports()` required both `ColorConfigurationProvider` and
  `ColorTokenResolver` to exist, but `essentials.yml`'s registration gate only checks the former.
  On a site with the older class but not the newer one (a real staggered-upgrade state — the two
  packages have no hard dependency on each other), the schema advertised `$palette()`/`$button()`
  token support, `supports()` silently declined the write, and `WriteApplicator` fell through to
  persisting the literal token string with a 200 response. `supports()` now claims the write
  whenever `ColorConfigurationProvider` exists and the value matches the token shape;
  `transform()` checks for `ColorTokenResolver` first and throws `TOKEN_RESOLUTION_FAILED` with an
  upgrade message instead of falling through. Writes previously (incorrectly) accepted on an
  affected site now return 422.

### Docs
- **(#76)** `docs/en/04_security-model.md`'s "Class-level gate" section claimed the gate was
  "deny-by-default...checked against the record's concrete class, so a subclass may narrow
  inherited access." Both halves were wrong: `api_access`/`content_api_access` are ordinary
  inherited config, so an undeclared subclass inherits its ancestor's verbs rather than being
  denied by default; and the concrete class the gate is checked against is whatever
  `RecordsHandler::fetchRecord()`'s `get_by_id()` returns, which can be a subclass reached under
  any mapped *ancestor* ref, not something the gate narrows to.

### Fixed
- **(#71)** Unpublishing (or archiving) a `Hierarchy` record with any live tree children used
  to silently cascade-delete every one of them too, recursively — not just the target record —
  confirmed live during a real IA restructure. Root cause: `SiteTree::onBeforeDelete()`
  cascades to `AllChildren()` under `SiteTree.enforce_strict_hierarchy` (the framework
  default), and `doUnpublish()` deletes the record from LIVE internally, firing that cascade
  against every current live child unconditionally — independent of whether those children had
  also been reparented in draft (an earlier version of this fix only guarded that narrower
  case, matching how the bug was first diagnosed; a live child that had never moved at all
  turned out to be cascade-deleted exactly the same way). `unpublish()`/
  `delete(mode: "unpublish")` now refuse with `409 UNPUBLISH_STRANDS_DESCENDANTS` whenever the
  record has any live descendants, naming the affected ids. `archive()`/
  `delete(mode: "archive")` share the same guard, checked against both stages (`doArchive()`
  calls `doUnpublish()` internally, then also deletes the draft-stage row directly — an
  equally cascading delete). `force: true` (the stage action's request body, or the batch
  delete op's `force` field) bypasses the guard for the case where the cascade is actually
  intended — bypassing now logs a warning naming the record and every descendant it stranded,
  so a forced cascade leaves an audit trail instead of vanishing without a trace. A record's own
  `PublishOrchestrator::MODES` `publish` field (used by `content_records_stage`'s `publish`
  action, previously hardcoded to only `single`/`recursive` via a `recursive` boolean) now
  accepts an explicit `mode` string including `subtree` — the guard's own documented remedy
  ("publish the subtree to its new parent first") was otherwise unreachable from that endpoint.
  See
  `docs/en/10_publishing-and-stages.md#unpublishing-or-archiving-a-hierarchy-record-the-descendant-cascade-guard`.

## [Unreleased] — ss5

This branch tracks branch `1` (synced via `git merge origin/1`, never cherry-picked) and carries
every entry above, plus the SS5-specific differences below. Baseline: SilverStripe `^5.2`, PHP
`^8.1`. See the README's Branch policy section for what's allowed to permanently differ between
the two branches.

### Added
- **(#76)** `Dynamic\ContentApi\Security\ContentApiGrantExtension`, ported from branch `1` — see
  the shared `[Unreleased]` entry above. No `Tasks/Support/ServiceAccountProvisioner` exists on
  this branch yet (pre-#65 extraction), so the equivalent wording change lives inline in
  `SetupServiceAccountTask.php` instead. **Deviation from branch policy**: ported via cherry-pick
  rather than `git merge origin/1`, since branch `1`'s source PR (#77) was still open at the time.
  ~~Re-sync via a real merge once #77 merges~~ — superseded by the #81 entry below: #77 has since
  merged, but the pending sync now also covers #65 and #70, neither of which is on this branch
  yet. A real `git merge origin/1` at this point would additionally drag in #65's ~180-line
  task-signature rewrite (the exact files the README's Branch policy section already flags as a
  deliberate, hand-ported SS5/SS6 divergence point) and all of #70 — both unrelated to whatever
  prompted the next port. Treat a real merge as its own separate, scoped piece of work, not a
  side effect of porting one more branch-`1` fix.
- **(#81)** `subtree` publish mode and the descendant-cascade guard (**#71**, PR #79 on branch
  `1`), ported here. `PublishOrchestrator.php`, `RecordActionsHandler.php`,
  `schema/endpoints.json`, and `docs/en/10_publishing-and-stages.md` were byte-identical to
  branch `1`'s pre-#79 state, so those landed verbatim. Branch-specific adjustments:
  - `ErrorCode.php`: `UNPUBLISH_STRANDS_DESCENDANTS` added independently of `ROLLBACK_UNVERIFIED`
    (#70), which isn't on this branch.
  - `RecordWriter.php`: only the `force` param/threading ported — the file's existing
    `SilverStripe\ORM\ValidationException` import (SS5 namespace) is untouched.
  - `BatchProcessor.php`: applied as a content-based edit (the file differs from branch `1` by
    ~99 lines, entirely due to #70's absence); #79's own change here is a self-contained 3-line
    `force`-threading addition.
  - `schema/endpoints.json`: bumped to `v1.5` with **one deliberate omission** — #79's
    `content_batch` description also added a sentence describing #70's `ROLLBACK_UNVERIFIED`
    verification, which doesn't exist on this branch. Porting it verbatim would have made this
    branch's published MCP spec advertise behavior it doesn't have. Every endpoint/field/enum is
    otherwise identical to branch `1`'s `v1.5`; only that one prose sentence differs.
  - `tests/Control/BatchTest.php`: only the new
    `testBatchDeleteWithUnpublishModeRoutesThroughTheDescendantGuard()` test + its two imports
    landed. #79's only other change to this file was a cosmetic multiline reformat of an array
    literal inside `testUnverifiedRollbackReportsDistinctlyFromAVerifiedOne()`, a pre-existing
    #70-only test that doesn't exist here — `ApiTestDeprecatingObject`/
    `ForceUnverifiedRollbackBatchProcessor` were already-present, unmodified imports that test
    used, not something #79 added.
  - **Framework-behavior verification**: unlike #76's port (which found a real `canDelete()`
    divergence), this guard's three load-bearing mechanisms
    (`Hierarchy::getDescendantIDList()`/`AllChildren()`, `Versioned::doUnpublish()`/`doArchive()`,
    `SiteTree::onBeforeDelete()`'s `enforce_strict_hierarchy` cascade) were confirmed
    byte-identical or semantically identical across three real installs, checking the actual
    packages each mechanism lives in (not just `silverstripe/versioned`, since
    `SiteTree::onBeforeDelete()` is `silverstripe/cms` and `Hierarchy` is `silverstripe/framework`):
    branch `1`'s testbed (`cms` 6.2.1, `framework` 6.2.2, `versioned` 3.2.1 — pinned releases),
    this branch's constraint floor `^5.2` as actually installed on `mathedleadership` (`cms`
    5.2.x-dev@c77a4c9, `framework` 5.2.x-dev@862a65e, `versioned` 2.2.x-dev@5bb8eb0 — dev-branch
    aliases, not tagged releases, despite `^2.2` reading like a floor pin), and a real SS5 site,
    `youth-sailing` (`cms`/`framework` 5.4.x-dev, `versioned` 2.4.x-dev) — no divergence found,
    confirmed live against `youth-sailing`.
  - **Deviation from branch policy, again**: cherry-picked rather than `git merge origin/1`, for
    the reasons in the #76 note above (a real merge would drag in #65 and #70, unrelated to this
    fix). See the follow-up merge-sync issue this entry links to on GitHub.

### Changed
- Tasks invoke via SS5's legacy `sake dev/tasks/<Segment> key=value` syntax, not branch `1`'s SS6
  `sake tasks:<Segment> --flag` syntax.
- `colymba/silverstripe-restfulapi` comes from `dynamic/silverstripe-restfulapi` `^5.0`, a
  maintained fork of silverstripeltd's `feature/v5` branch fixing 4 calls to methods removed in
  SilverStripe 4+ (`Member::login()`/`logout()`, `DataObject::stat()`). See
  [docs/en/upstream-issues.md](docs/en/upstream-issues.md).

### Docs
- **(#76)** Ported the `04_security-model.md` class-level-gate correction above, plus one
  genuine branch-specific divergence found while verifying live against a real SS5.2 site
  (`silverstripe/versioned` `2.4.x-dev`): unlike branch `1`, `Versioned` has no `canDelete()`
  override on this branch's dependency version at all — only a deprecated `canArchive()` whose
  own docblock says to use `canDelete()` instead on the version branch `1` depends on. A class
  declaring only the `delete` verb can therefore archive an already-published record on this
  branch, where it cannot on branch `1`. See `ContentApiGrantExtension`'s "BRANCH NOTE" docblock
  and `ContentApiGrantExtensionTest::testCanDeleteOnAPublishedRecordDoesNotNeedTheEditGrantHere()`.
- **(#81)** See the shared `[Unreleased]` → `### Fixed` entry above for #71's descendant-cascade
  guard — identical on this branch, no divergence found (see the `### Added` entry above for the
  verification detail). `#72` (test DB isolation) and `#70` (output buffering, rollback
  verification) are separate, still-unported branch-`1` fixes not included in this port.

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
