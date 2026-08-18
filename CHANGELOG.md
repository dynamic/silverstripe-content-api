# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Removed
- **(#150)** `ContentApiController.cors_enabled` — configured and documented but never read
  anywhere in PHP; the controller emitted no `Access-Control-*` headers and had no `OPTIONS`
  handling regardless of its value. Removed the dead property, its `_config/config.yml` default,
  and the docs paragraph, and replaced the paragraph with an explicit statement of the actual
  position: `/content-api/v1` is server-to-server/agent-only and has no CORS surface at all.
  Colymba's own `cors.Enabled` (governing the separate `/api` surface) is unaffected.

### Fixed
- **(#136)** `DbTransaction::run()` — the one place every write path in this module opens a
  transaction — now catches `\Error` (a `TypeError`/`ArgumentCountError`, not `\Exception`)
  escaping the work closure and rolls back explicitly before re-throwing. Framework
  `Database::withTransaction()` only ever catches `Exception`, so an `Error` mid-write previously
  left `transactionRollback()` uncalled and the transaction open at its current nesting level —
  the `#70`/`#127` rollback-verification safety net was silently bypassed exactly when the
  transaction's own bookkeeping was most likely to be in a bad state. Deliberately catches
  `\Error`, not `\Throwable` — catching `Exception` too would roll back a second time for the
  path the framework already handles correctly on its own.

  **Remaining scope, not covered by this fix**: `BatchProcessor::process()`'s atomic path and
  `runDryRun()` still report a bare `SERVER_ERROR` 500 rather than `ROLLBACK_UNVERIFIED` when an
  `Error` escapes a batch operation — `$results`/`$summary` are locals inside `run()` that only
  escape via `BatchAbortException`, so per-op reporting on the `Error` path needs a separate
  follow-up converting an `Error` into a `BatchAbortException` at the per-op boundary. This fix
  closes the data-integrity hole (nothing is left half-committed); it doesn't yet restore
  accurate error reporting on that specific path.

  Verified against the real production shape, not just a single top-level call: a nested
  `DbTransaction::run()` (`BatchProcessor` opening one transaction, `RecordWriter::write()`
  opening another inside it per op — the exact shape `NestedTransactionManager`'s savepoint
  bookkeeping engages for) correctly rolls back both levels and leaves `transactionDepth()`
  balanced when an `Error` escapes the inner call.

### Docs
- **(#147)** `docs/en/15_testing-and-contributing.md` now documents the actual pre-push gate:
  Actions is disabled on this repo (`actions/permissions` → `enabled: false`), so `local-ci` —
  which auto-detects PHPUnit/phpcs/phpstan from their own config files and additionally runs the
  project-declared `scripts/check-doc-drift.sh` (`.local-ci.json`) — is the real test/lint gate,
  not a GitHub Actions workflow that was never going to run.

### Changed
- **(#125)** `ClassRegistry::resolve()`'s `UNKNOWN_CLASS` error now distinguishes the two ways a
  class ref can fail, instead of one message covering both: a ref with no `models:` entry at all
  now names the real, autoloadable class it most likely refers to when one exists (matched on
  basename, case-insensitively) and says to add a `models:` entry, rather than reading like a
  typo or routing problem — the exact miss that cost real investigation time on
  `sheboygan-youth-sailing-installer`'s `SearchPage`. A `models:` entry pointing at an FQCN that
  doesn't autoload (stale config, not a caller mistake) gets its own distinct message. Both cases
  still throw `UNKNOWN_CLASS`/404 — introducing a second error code would be a breaking change to
  any caller branching on `error.code` for what's purely a message-quality gap — but now carry
  structured `details` (`CLASS_NOT_MAPPED` / `CLASS_NOT_FOUND` / `CLASS_ALREADY_MAPPED`) a caller
  can act on programmatically. The suggestion never names a `discoveryDenylist()` class
  (`Member`/`Group`/`Permission`/etc. — caught in review before merge: a first draft suggested
  registering exactly the classes the module hardcodes as never-exposable), and when the matched
  class is already registered under a different ref, says so (`CLASS_ALREADY_MAPPED`) instead of
  wrongly suggesting a duplicate `models:` entry.

## [1.6.0] - 2026-08-07

Numbered `1.6.0`/`1.5.0`, not `1.1.0`/`1.2.0`: `1.1.0`-`1.4.0` are already used below by real,
dated pre-rename history this file's own no-rewrite rule keeps as-is (see the "predates the
rename" note further down). The `1.0.0` tag was reset per #106 for a clean `composer require ^1`
resolution, but this CHANGELOG's own version-heading sequence never reset, so the next numbers
continue from `1.4.0`, not from the reset tag — and skip straight to `1.6.0` here because `1.5.0`
(below) backfills work the reset `1.0.0` tag's own commit already contained but never got its own
heading, while everything in this section is genuinely new since that tag.

### Added
- **(#131)** `GET fingerprint`: a deterministic, path-keyed snapshot of the site's content for
  diffing across gates (before/after a batch, same environment) or across environments (a local
  rehearsal vs. production ahead of a replay), via new `Dynamic\ContentApi\Verify\
  FingerprintService`. Pages are keyed by URL path, not id — ids churn across a rebuild/re-seed/
  environment boundary, paths don't; the path is an in-memory `ParentID` walk forced to `DRAFT`
  explicitly regardless of the ambient reading mode (a plain unstaged query would otherwise
  silently drop every draft-only page from the enumeration entirely whenever the ambient mode
  happened to be Live, not merely report it as not-live). Also asserts the reachability
  invariant the issue is named for: `violations` lists every live page (or live `related` record,
  one hop out through its own owner's ancestor chain) whose path runs through a non-live
  ancestor — `SiteTree::get_by_link()` 404s on the first non-live path segment regardless of the
  target row's own live status, and the real-world prototype's own fingerprint output already
  contained both contradicting lines with nothing checking them against each other. Duplicate
  violation entries describing one shared blocked owner page collapse to one. `related` sections
  are project-configured (a class plus its direct owner-page FK column, e.g. `'HeroSlide' =>
  'PageID'`); an owner id that doesn't resolve to a known page is counted in `unresolved`, never
  leaked as a raw id in `records` (the prototype's own known weakness) — `includeIds=true` instead
  surfaces a separate `unresolvedIds` list for tracking down a broken owner FK. `classes=`
  restricts which sections appear in the response without affecting internal owner-path
  resolution — narrowing to a `related` ref without `pages` still resolves through the same index
  a full scan would use, and `pages` is reported in `meta.skipped` whenever the index genuinely
  couldn't be built even if `classes=` never asked for it. Every collection is sorted (`related`
  rows sharing one owner path tiebreak on external id) and ids are excluded from `data` by
  default, so two fingerprints (or the same one twice) diff as plain text/structure. Applies the
  same class- and record-level ACL as every other read endpoint, per row — a class not exposed to
  the content API at all is reported in `meta.skipped` (site config, not per-caller); a class that
  IS exposed but a specific row this caller can't view (a draft-only page without
  `VIEW_DRAFT_CONTENT`, an explicit per-subclass `content_api_access: false` override, or a class
  exposed for write but not `read`) is simply absent from the response, the same way
  `RecordsHandler::readList()` filters a list — caught and fixed in review before this endpoint
  ever shipped without it, since a first draft applied only a coarse "does this token have
  `CONTENT_API_ACCESS` at all" gate and would otherwise have disclosed every draft-only/restricted
  page's path, class, and live status to any holder of that one permission code. A second review
  round on that ACL fix itself caught: `totals` was still computed over the unfiltered internal
  state (leaking exactly how many hidden records exist, and disagreeing with the row counts a
  restricted caller could actually see) — now counted only over what the caller can view, matching
  `RecordsHandler::readList()`'s own #20 precedent; `unresolvedIds` could leak a record's id even
  when that record itself was invisible to the caller (the ACL check ran after, not before, the
  unresolved-owner branch); `violations` was silently suppressed by `classes=` excluding a
  section's OUTPUT, when a reachability problem must be reported regardless of which sections were
  requested — the identical "a check that only runs when asked for reads as false 'no drift'"
  reasoning that already made an unknown `classes=` ref a hard rejection. Also pre-populates
  `Versioned`'s per-record version-number cache before the per-row ACL check, avoiding an
  otherwise-unbounded per-page query count across a whole-site scan. Spec bumped to `v1.11`. See
  [docs/en/16_verification.md](docs/en/16_verification.md).
- **(#120)** `GET records/$ClassRef/$ID/parity`: does this record, and everything it `$owns`,
  match between draft and live, and where do they differ. Compares a configurable set of the
  root's own fields (`Title`/`ParentID`/`ClassName`/`ShowInMenus`/`URLSegment`/`Sort` by default,
  filtered to whichever the class actually declares) draft vs live — the record not existing on
  live at all is reported as `liveExists: false`, a legitimate state, not a failure. Also walks
  the record's `$owns` tree recursively via new `Dynamic\ContentApi\Verify\OwnedTreeWalker` (the
  module's first `$owns` walker — no class in `src/` declared one before this; `?include=none`
  skips the walk, `?depth=N` caps it), reporting each owned descendant's live/draft status and
  depth, not a field-level diff (only the root gets that). An owned descendant's live status
  disagreeing with the root's own is a mismatch (`ok: false`) either direction — root live +
  descendant draft-only (the primary bug class this endpoint targets) or root not-live +
  descendant live (stranded content); root and descendant agreeing, live or not, is consistent.
  Both the root and every owned record are queried by their true base class, not the
  (potentially narrower) requested/concrete class — a record converted to a different class on
  draft only has a live row whose `ClassName` differs, and querying through the narrower class's
  own subclass set would otherwise silently miss it (a subclass's own subclass set never
  includes its ancestor), reporting a genuinely-live, genuinely-divergent record as
  `liveExists: false` / `ok: true`. `OwnedTreeWalker` adds a cycle guard and a depth cap neither
  the `DraftLiveParityTask`/`GoLivePublishTask` prototypes this generalizes had, walks THROUGH
  (without reporting) an unversioned intermediate record rather than pruning its whole branch —
  matching `RecursivePublishable`'s own real recursion behavior — and resolves a diamond (the
  same owned record reachable via two different paths at different depths) to its shallowest
  depth rather than whichever path happened to reach it first. `?depth=` rejects a non-numeric
  value rather than silently coercing to `0` (which would disable the whole owned walk);
  `?include=` rejects anything other than `owned`/`none`. Authorization checks every owned
  class/record before any part of the response is built — a forbidden branch fails the whole
  request rather than silently disappearing from the report. `400 PAYLOAD_INVALID` for a
  non-Versioned class. Response carries both a machine-readable structure
  (`fields`/`owned`/`liveExists`/`ok`) and a flat `report: [{label, ok, message}]` list, so a
  project can drop its own copy of `DraftLiveParityTask::report()`. Spec bumped to `v1.10`. See
  [docs/en/10_publishing-and-stages.md#draftlive-parity](docs/en/10_publishing-and-stages.md#draftlive-parity).
- **(#130)** `"dryRun": true` on `POST batch`: runs the batch exactly as a real request would
  (same authorization, class/externalId/relation resolution, payload validation, and model
  `validate()` on write) inside a transaction that's unconditionally rolled back afterward,
  regardless of `atomic` or whether every op succeeded — nothing is ever persisted. Subsumes the
  "create a scratch record, then archive it" pattern previously needed to prove a token is
  writable before a real batch run. Response statuses are prefixed `would`
  (`wouldCreate`/`wouldUpdate`/`wouldDelete`; `error` unchanged) in both `results[]` and
  `summary`, and `meta` carries `{"operation": "batchDryRun", "atomic": <bool>}`, so a caller
  inspecting `status` can never mistake this for a confirmed write — same convention as #102's
  subtree-publish `dryRun`. Rollback is verified the same way an atomic failure's is (#127's
  `verifyRollback()`) — "wrapped in a transaction that gets rolled back" is exactly the mechanism
  #70 proved isn't trustworthy on its own; a dry run that fails verification reports
  `500 ROLLBACK_UNVERIFIED`, the loudest possible failure, never folded into the normal dry-run
  response — and, deliberately, **not** mapped through the `would*` vocabulary: that path uses
  real verbs (and a real delete's `deleted: true`), since at that point the caller genuinely
  doesn't know whether the write committed for real. Verification is deliberately lenient here
  (on the success/mapped path) where the real-atomic-failure caller is
  strict: an `update` op whose payload is `relations` only (the module's normal element-attach
  shape) has nothing for the pre-image mechanism to check either way — nothing failed and the
  batch is rolled back regardless, so that's treated as "nothing to check," not "verification
  failed" (`verifyRollback()` gained a `$strict` parameter for this; the real atomic-failure path
  is unchanged). `dryRun` is a batch-only feature — every other write endpoint
  (`compositions/page`, `pages/$ID/convert`/`apply-template`,
  `records/$ClassRef/$ID/unpublish`/`archive`, `assets`) rejects it outright
  (`400 PAYLOAD_INVALID`) rather than silently ignoring it; `records/.../publish` is the one
  exception, where `dryRun` already meant #102's subtree-publish dry run. Ids in a dry-run
  response are ephemeral — a rolled-back insert still consumes an `AUTO_INCREMENT` value — and a
  `wouldDelete` result's `deleted` field is always `false`, unlike a real delete's. Spec bumped to
  `v1.9`. See [docs/en/07_batch-operations.md#dry-run](docs/en/07_batch-operations.md#dry-run).
- **(#127)** Atomic batch rollback verification now covers `updated` results, not just
  `created`/`deleted`. `RecordWriter::write()` snapshots the declared `fields` keys before an
  update writes, and `BatchProcessor::verifyRollback()` re-reads the row afterward to confirm
  those fields are genuinely back at their prior values — the same "don't just trust the
  transaction unwound, check" verification `created`/`deleted` already had. Only the declared
  `fields` are covered: an `update` whose payload carries only `relations` has no pre-image to
  check against and reports `ROLLBACK_UNVERIFIED` rather than a false `rolledBack: true` for a
  check that never ran; relation changes on any `update` remain outside this check's scope.
  Verification also still reads DRAFT only. A has_one field's pre-image is the raw foreign key
  (and, for a polymorphic has_one, the companion class column too), not the related record, so
  the check reflects which record is actually linked, not just whether one is; a composite
  DBField (e.g. Money) expands to its real, always-scalar sub-columns rather than the composite
  object itself, whose own string representation isn't a reliable proxy for its content unless
  that specific subclass happens to override `getValue()`. A record written more than once in
  the same batch is checked, per field, against its state before the earliest write of that
  field — matching what a genuine rollback actually restores it to, even when different ops
  touched different fields on the same record. See
  [docs/en/07_batch-operations.md](docs/en/07_batch-operations.md).

## [1.5.0] - 2026-08-07

Everything below was already present in the reset `1.0.0` tag's own commit (2026-08-06) — the
tag reset per #106 was a clean composer-resolution reset, not a content reset, and this
CHANGELOG's `[Unreleased]` section was never actually emptied out under its own heading at that
point. Backfilled here, under a heading number that doesn't collide with the preserved pre-rename
`1.1.0`-`1.4.0` history below, so the version-heading sequence has a place for it. No code in
this section is new relative to `1.0.0` — a consumer already on `1.0.0` sees no functional change
upgrading to `1.5.0`.

### Added
- **(#64)** Elemental's own `allowed_elements`/`disallowed_elements` per-page-type config is now
  enforced on composition and batch/upsert/update — a request that newly places (or re-places) a
  `BaseElement` onto an `ElementalArea` whose owning page doesn't permit that element class is
  rejected with the new `ELEMENT_NOT_ALLOWED_ON_PAGE` (422) error, listing the page's actual
  allowed types. New `Dynamic\ContentApi\Registry\ElementPlacementPolicy` delegates entirely to
  `ElementalAreasExtension::getElementalTypes()` (the CMS's own canonical check) rather than
  reading the config directly, so `stop_element_inheritance`, per-element `canCreate()`, and the
  `updateAvailableTypesForClass` hook all keep working exactly as they do in the CMS. Enforced
  once, in `RecordWriter::write()`, since composition and batch/upsert/update all funnel through
  it — and only when placement actually changes, so a plain content edit to an already-existing
  element never gets caught out by a later config change (this module's own re-POST idempotency
  guarantee depends on that). Any `ElementalArea`-type has_one FK is no longer directly
  client-writable at all — enforced in `WriteApplicator::isFieldWritable()`, the module's single
  shared writability decision (colymba `/api`, batch, compositions, and `SchemaService`'s
  advertised writability all read it), not just in the composition/batch write path — except a
  `BaseElement`'s own `Parent` relation, the one FK `ElementPlacementPolicy` is built to check,
  not to block. Closes the side door this check would otherwise have left open: repointing a
  restricted page's area onto a different, already-populated area without any of *that* area's
  elements passing through the new check, on any write surface.
  **Known gaps, not covered by this change** (tracked as follow-up issues): the colymba `/api`
  surface can still create/attach an element via its own `ParentID` — a `BaseElement`'s FK is
  exempt from the guard above by design, and that surface never routes through `RecordWriter`'s
  placement check at all; attaching an *existing* element via `ElementalArea.Elements`
  `api_writable_relations` (`WriteApplicator::applyRelations()`) is the same. Neither is a
  regression introduced here, both are pre-existing gaps in how those surfaces relate to this
  module's write pipeline. See [docs/en/12_error-codes.md](docs/en/12_error-codes.md) and
  [docs/en/08_page-compositions.md](docs/en/08_page-compositions.md).
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

### Changed
- **(#65)** `MintApiTokenTask`/`SetupServiceAccountTask` business logic extracted to
  branch-neutral services (`Tasks/Support/ApiTokenMinter`, `ServiceAccountProvisioner`) so a
  future `ss5` port can share it instead of duplicating ~180 lines per branch. Two small
  wording changes as a result: `MintContentApiToken`'s missing-option message now reads
  `Missing required option: --email` (matching `SetupContentApiServiceAccount`'s existing
  `--group cannot be empty.` convention, was `Missing required option: email`);
  `SetupContentApiServiceAccount`'s success output states the account still needs a token
  minted without embedding the exact command inline (the command itself — `sake
  tasks:MintContentApiToken --email=<member-email>` — is now a separate line the task prints
  after, unchanged from before).

### Docs
- **(#76)** `docs/en/04_security-model.md`'s "Class-level gate" section claimed the gate was
  "deny-by-default...checked against the record's concrete class, so a subclass may narrow
  inherited access." Both halves were wrong: `api_access`/`content_api_access` are ordinary
  inherited config, so an undeclared subclass inherits its ancestor's verbs rather than being
  denied by default; and the concrete class the gate is checked against is whatever
  `RecordsHandler::fetchRecord()`'s `get_by_id()` returns, which can be a subclass reached under
  any mapped *ancestor* ref, not something the gate narrows to.

### Fixed
- **(#72)** `SetupServiceAccountTaskTest` had no fixture file and no `$usesDatabase = true`, so
  it never got an isolated temp DB or per-test transaction rollback — every local run wrote
  real `Group`/`Permission` rows into the host project's live dev DB, colliding with the next
  run's data. The new `Tasks/Support` tests inherit the fix.
- **(#70)** A non-`Throwable` PHP diagnostic mid-request — most notably a deprecation notice
  from application code this module doesn't control (e.g. a third-party DataObject's
  `onBeforeWrite()`) — never reached `ContentApiController`'s own exception handling, because
  it doesn't throw. In dev/test environments SilverStripe's default error handler `echo`s an
  HTML debug block directly to output for exactly this case, bypassing the controller's
  `HTTPResponse` entirely; left unbuffered, that HTML got sent ahead of (and concatenated
  with) the endpoint's own well-formed JSON body, corrupting the response into something
  neither valid JSON nor an accurate reflection of the real outcome — confirmed against a real
  HTTP request, where the underlying write had actually succeeded. `withEnvelope()` now
  buffers all output from the endpoint callable (drained down to the level it opened, so an
  unbalanced buffer left open by application code doesn't slip past it); any stray output is
  logged (not silently discarded) rather than allowed to reach the response. Known gap this
  can't close: SilverStripe's own `Deprecation::notice()` defers its output to request
  shutdown, after the response is already sent, so it can't be buffered here — and a true PHP
  fatal (not a `Throwable`) never returns control to drain the buffer at all. Separately,
  `BatchProcessor` no longer trusts that an atomic batch's caught-exception rollback path means
  the SQL `ROLLBACK` actually took effect — every operation reported `created` is now
  re-checked by id against the database (using an uncached, non-deprecated lookup) before the
  response claims `rolledBack: true`; if any is still there, or the check itself fails for an
  unrelated reason, the response reports the new `500 ROLLBACK_UNVERIFIED` instead, carrying
  the same full results array so every `created` entry can be checked by hand. `updated`
  results still aren't independently verified — no pre-image is retained to compare against.
  `deleted` results are now verified too (#75, below). See `docs/en/12_error-codes.md`.
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
- **(#75)** `BatchProcessor::verifyRollback()` (#70) only re-checked `created` results after a
  claimed atomic rollback — a `deleted` op's id was retained in the results array too, but its
  verifiability depends on the delete mode, so it was left unverified rather than half-checked.
  Now verified when the check is actually meaningful: `mode: "archive"`, or any mode at all on
  an unversioned class (every mode converges on a real `delete()` there) — both reach the draft
  row, so a genuine rollback restores it and its continued absence means the delete committed
  for real despite the claim. `mode: "unpublish"` on a versioned class is still correctly
  skipped: it only ever touches the live stage, so checking draft would read as falsely "fine"
  regardless of the real outcome. Also swapped `BatchProcessor::fetch()`'s deprecated
  `DataObject::get_by_id()` for `DataObject::get($className)->byID(...)`, matching
  `verifyRollback()`'s own style (spotted while touching this file; unrelated deprecated call
  sites elsewhere in `src/` are out of scope here). Spec bumped to `v1.6`.

## [Unreleased] — 1

This branch (formerly `ss5`) tracks branch `2` (formerly `1`; synced via `git merge origin/2`,
never cherry-picked) and carries every entry above, plus the SS5-specific differences below.
Baseline: SilverStripe `^5.2`, PHP `^8.1`. See the README's Branch policy section for what's
allowed to permanently differ between the two branches. Entries below predate the rename
(issue #105) and use each branch's own then-current name.

### Fixed
- **(#90, #102)** `PublishOrchestrator::publishSubtree()` had two real gaps: no per-descendant
  authorization check (a token scoped to `Page` could publish a descendant of a subclass whose
  own `api_access` only grants `read`, or a member without CMS access to a specific child page
  could publish it via an ancestor they can edit — #90), and no way to avoid resurrecting a
  deliberately-unpublished descendant when publishing a live ancestor's subtree (#102). Fixed
  together since both live in the same walk:
  - `publish()`/`publishSubtree()` now require a `Member` and authorization-check every
    descendant (`checkClassAccess`/`checkRecordAccess`, `action` verb) before writing anything —
    a two-pass design (collect + check the whole subtree, then publish) so a permission gap
    partway through refuses the whole call rather than leaving earlier descendants live with no
    way to undo it. `RecordActionsHandler`, `PageHandler`, `CompositionService`, and
    `RecordWriter` all pass the acting member through.
  - `mode: "subtree"` gained `liveOnly` (skip a descendant branch — no publish, no recursion —
    that isn't already live) and `dryRun` (preview the would-publish set, no writes) request
    body fields on the `publish` record action. **Breaking for existing callers**: both are
    `400 PAYLOAD_INVALID` on any mode other than `subtree` — an earlier version of this fix
    silently ignored them there instead, which meant `{"dryRun": true}` with no `mode` (or any
    non-subtree mode) performed a real write while the response claimed a preview; caught via
    `/review-pr` before merge. MCP spec bumped to `v1.8` to document both.
  - A real (non-`dryRun`) `subtree` call keeps the normal serialized-record response but adds
    `meta.published`: the same `[{id, className}, ...]` list a `dryRun` call would preview, so a
    real `liveOnly` call still reports what it actually touched (there's no separate skipped
    list — a `liveOnly`-skipped descendant simply doesn't appear).
  - `RecordWriter::write()` now runs `publish()` inside the same DB transaction as the field
    write and relation writes, so a `subtree` descendant-authorization failure there rolls back
    the field write too, rather than leaving it committed while the batch op reports "error".
  - `docs/en/10_publishing-and-stages.md`'s existing recommendation to use `subtree` before
    unpublishing an old wrapper now carries the resurrection-risk warning and the `liveOnly`
    fix, rather than silently recommending a footgun.
- **(#108)** `PublishOrchestrator`'s class docblock, and the matching passage in
  `docs/en/10_publishing-and-stages.md`, framed `publishRecursive()` not cascading into owned
  Elemental blocks as an SS6-specific fact. Issue #91's empirical test confirmed it's identical
  on both branches — reworded both to say so, and fixed a second inaccuracy in the same
  docblock/doc passage that #91 also flagged: text nearby asserted `publishRecursive()` cascades
  to owned relations generally, which doesn't hold for the Elemental relation specifically.
  (#107, the four `Tasks/Support/*` docblocks tracked alongside this one for the same "pending a
  follow-up port" staleness, turned out to already be correct — fixed as a side effect of the
  #105 branch-rename sweep.)

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
- **(#96)** Real `git merge origin/1` landed — the sync both entries above deferred. Brings #65
  (task-service extraction), #70 (rollback verification, output buffering), and #75 (archive-mode
  rollback verification) onto this branch for the first time, plus a fresh #64 (Elemental
  element-placement enforcement) that landed on branch `1` after #81. `ErrorCode.php`,
  `schema/endpoints.json` (now `v1.7`), `Registry/ElementPlacementPolicy.php`,
  `RecordWriter.php`'s placement check, and `WriteApplicator.php`'s `ElementalArea` FK guard all
  merged in verbatim — no SS5-specific divergence found in any of them. Closes the "still needed"
  real-merge caveat both the #76 and #81 entries above left open.

### Changed
- Tasks invoke via SS5's legacy `sake dev/tasks/<Segment> key=value` syntax, not branch `1`'s SS6
  `sake tasks:<Segment> --flag` syntax.
- `colymba/silverstripe-restfulapi` comes from `dynamic/silverstripe-restfulapi` `^5.0`, a
  maintained fork of silverstripeltd's `feature/v5` branch fixing 4 calls to methods removed in
  SilverStripe 4+ (`Member::login()`/`logout()`, `DataObject::stat()`). See
  [docs/en/upstream-issues.md](docs/en/upstream-issues.md).
- **(#96)** `MintApiTokenTask`/`SetupServiceAccountTask` now delegate to the same branch-neutral
  `Tasks/Support/ApiTokenMinter`/`ServiceAccountProvisioner` services branch `1` uses (#65 above),
  kept as thin `run($request)` adapters instead of ~180 lines of duplicated inline logic each.
  `TaskResultRenderer` (branch `1`'s Symfony `Command`/`PolyOutput` rendering glue) is SS6-only and
  not present here — these adapters `echo` `TaskResult::$lines` directly instead. The two wording
  changes #65's shared entry above describes now apply on this branch too, with one further
  translation on top: `ApiTokenMinter`/`ServiceAccountProvisioner`'s own message text names
  branch `1`'s SS6 `--flag` syntax (`--email`, `--group`) since the two services are shared —
  each adapter here rewrites that to this branch's `key=value` syntax before it reaches the
  operator, so a `sake dev/tasks/` user is never told to pass a flag this branch's
  `run($request)` entry point doesn't accept. Their previously monolithic tests split the same
  way branch `1`'s did: behavioral coverage (group creation, idempotence, healing,
  duplicate-title refusal, validation) moved to the branch-neutral
  `tests/Tasks/Support/ServiceAccountProvisionerTest.php`; `tests/Tasks/*TaskTest.php` now cover
  only the `run($request)` adapter's own request-var parsing, syntax translation, and output.

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
  verification) were separate, still-unported branch-`1` fixes not included in this port — both
  landed via #96 below.
- **(#96)** Resolved a silent, no-conflict-marker doc duplication the merge itself produced in
  `docs/en/04_security-model.md`: both branches had independently added a grant-extension section
  at slightly different offsets, so a plain `git merge` appended both rather than conflicting.
  Kept this branch's SS5-correct `Versioned::canDelete()` note, dropped branch `1`'s duplicate
  (which asserts the opposite behavior). Also expanded the README's "Branch policy" list of what's
  allowed to permanently differ — it previously covered only composer constraints, task
  entry-point signatures, and requirement statements; now also names PHP-floor-dependent type
  declarations (`?bool` here vs `true|null` on branch `1`, since standalone `true` as a type needs
  PHP 8.2+) and SS6-only task-rendering glue (`TaskResultRenderer`) being absent here. Also
  corrected `Tasks/Support/ApiTokenMinter`/`ServiceAccountProvisioner`'s class docblocks, which
  still described `ss5`'s adapters as carrying inline logic "pending a follow-up port" — stale as
  of this merge; the same correction is needed on branch `1`'s copies, tracked as a follow-up.

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

[1.6.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.5.0...1.6.0
[1.5.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.0.0...1.5.0
[1.4.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.3.0...1.4.0
[1.3.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/dynamic/silverstripe-content-api/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/dynamic/silverstripe-content-api/releases/tag/1.0.0
