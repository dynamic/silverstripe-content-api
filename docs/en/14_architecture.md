# Architecture

For contributors modifying the module. Assumes familiarity with [Security model](04_security-model.md)
and [Write payloads](06_write-payloads.md).

## Request lifecycle

`ContentApiController` (`_config/config.yml` routes `content-api/v1` to it) is the routing hub:
it owns the JSON envelope, the authentication gate, and `ApiError`→HTTP response conversion.
All endpoint logic lives in Injector-swappable handler services — the controller itself never
touches a `DataObject`.

```
HTTPRequest
  → ContentApiController::requireAuth()        (token → AuthContext, no session login)
  → ContentApiController::withEnvelope(fn)      (catches ApiError / Throwable, wraps response)
  → {Handler}::{method}(request, authContext)   (endpoint logic)
  → {data, meta} or ApiError
  ← {"data": ..., "meta": {...}, "error": null|{...}}
```

Each route maps to one handler method (`$url_handlers` in `ContentApiController`):

| Route | Handler |
|---|---|
| `GET auth/session` | `AuthHandler::session()` |
| `GET/POST records/*` | `RecordsHandler` (reads), `RecordActionsHandler` (stage actions), `ParityHandler` (draft/live parity, #120) |
| `GET fingerprint` | `FingerprintHandler` → `FingerprintService` (path-keyed content snapshot + reachability check, #131) |
| `POST pages/$ID/*` | `PageHandler::handle()` |
| `POST/GET assets*` | `AssetHandler` |
| `POST batch` | `BatchHandler` → `BatchProcessor` |
| `POST compositions/page` | `CompositionHandler` → `CompositionService` |
| `GET schema*` | `SchemaHandler` → `SchemaService` |

`withEnvelope()` catches `ApiError` (converted to the structured error envelope at its declared
HTTP status) and any other `Throwable` (logged, then surfaced as `SERVER_ERROR` — full exception
class+message in dev/test, an opaque message in production).

## Service map

| Directory | Class | Responsibility |
|---|---|---|
| `Registry/` | `ClassRegistry` | Short-ref ↔ FQCN mapping (colymba base + module overlay), verb resolution, polymorphic class-hint resolution |
| `Security/` | `ContentApiPermissions` | Permission code definitions |
| `Security/` | `EnvironmentGate` | Population-endpoint environment gating |
| `Security/` | `PermissionPolicy` | Two-stage ACL: class-level (config + permission codes) and record-level (`can*()`), plus `canCreate()` context hydration |
| `Identity/` | `ExternalIdResolver` | External-id lookup/matching (the idempotency key) |
| `Identity/` | `ExternalIdentifierExtension` | Adds the `FixtureIdentifier` column |
| `Write/` | `WriteApplicator` | Field/relation writability decision (single source of truth) + apply |
| `Write/` | `RecordWriter` | Request-independent write pipeline: ACL → apply → write → relations → publish |
| `Write/` | `WriteGuardExtension` + `WriteGuardPayloads` | Field guard for colymba's own write path (see below) |
| `Write/Transformers/` | `ColorTokenTransformer`, `LinkTransformer` | `ValueTransformer` implementations, registered conditionally per installed integration |
| `Batch/` | `BatchProcessor` | Ordered op execution, atomic transaction wrapping |
| `Composition/` | `CompositionService` | Full-page composition orchestration |
| `Publish/` | `PublishOrchestrator` | Every stage transition (publish/unpublish/archive/delete), the single "is this record publishable" answer |
| `Assets/` | `AssetService` | Asset ingestion, conflict resolution, hash-skip |
| `Serialize/` | `RecordSerializer` | DataObject → API record shape |
| `Schema/` | `SchemaService` | Site/class introspection |
| `Verify/` | `OwnedTreeWalker` | Recursive `$owns` tree walk with cycle guard + depth cap (#120) |
| `Verify/` | `FingerprintService` | Deterministic, path-keyed content snapshot + ancestor-reachability check (#131) |
| `Auth/` | `AuthContext` | Resolved-auth value object for one request |
| `Errors/` | `ErrorCode`, `ApiError` | Machine-readable codes + the throwable that carries them |
| `Control/`, `Control/Handlers/` | `ContentApiController` + 10 handlers | Routing, envelope, per-endpoint logic |
| `Tasks/` | `MintApiTokenTask`, `SetupServiceAccountTask`, `CheckGrantExtensionReachabilityTask` | `sake dev/tasks/MintContentApiToken`, `sake dev/tasks/SetupContentApiServiceAccount`, `sake dev/tasks/CheckGrantExtensionReachability` — thin `run($request)` adapters, translate legacy SS5 request vars only |
| `Tasks/Support/` | `ApiTokenMinter`, `ServiceAccountProvisioner`, `ServiceAccountMemberProvisioner`, `TaskResult`, `TaskStatus`, `GrantExtensionReachabilityChecker` | Branch-neutral business logic behind the tasks above (#65/#103), behaviorally identical to branch `2`'s copies (class docblocks may name each branch's own adapter; code and returned messages must not differ) — this branch's adapters call the same services instead of duplicating ~180 lines per branch. Dependency-free (no `symfony/console`, which this branch doesn't require). The services' own message text still names branch `2`'s `--flag` syntax, so each adapter here translates it to this branch's `key=value` syntax before echoing. Branch `2` additionally has `TaskResultRenderer`, the one piece of SS6-specific (`PolyOutput`/`Command`) rendering glue — not present here; this branch's adapters `echo` `TaskResult::$lines` directly. `ServiceAccountMemberProvisioner` (#124) find-or-creates the service-account `Member` itself and attaches it to the group — a separate, explicitly opt-in step from `ServiceAccountProvisioner`, wired in via `SetupServiceAccountTask`'s `member=` var |

## The two write surfaces

**This module's own pipeline** (`RecordWriter` → `WriteApplicator`): used by single-record
writes, `BatchProcessor`, and `CompositionService`. Shared shape: ACL check → sparse
`applyFields()` (validates everything before touching the record; a bad payload changes
nothing) → `$record->write()` (SS `ValidationException` mapped to structured `ApiError` details)
→ `applyRelations()` → `PublishOrchestrator::publish()`. A disallowed field **rejects the whole
payload**.

**Colymba's `/api` write path + `WriteGuardExtension`**: colymba's `DefaultQueryHandler` applies
every JSON payload key directly with no writability concept. `WriteGuardExtension` hooks two
DataObject extension points on the target model:

- `onBeforeDeserialize(&$rawJson)` — before colymba deserializes: strips has_many/many_many keys
  not in `api_writable_relations`, and translates any polymorphic has_one's `{"class","id"}`
  payload into raw `{Name}ID`/`{Name}Class` columns (colymba's deserializer has no concept of
  either shape) using the exact same `WriteApplicator::resolveRelation()` the native path uses.
  The translated/original payload is stashed in `WriteGuardPayloads` (a request-scoped map
  keyed by object instance, not an extension-instance property — extension instances are
  effectively shared per class, so an instance property would leak one write's payload onto a
  sibling/cascade write of the same class).
- `onBeforeWrite()` — after colymba applies the payload to the in-memory object but before
  `write()`: reverts (via `getChangedFields()`) any field the payload named that
  `isFieldWritable()` says isn't allowed. A polymorphic has_one's FK+Class pair reverts as a
  unit when it went through the translate step; an untranslated raw `{Name}Class` key sent
  directly (bypassing the wrapped shape) is never independently writable, matching the same
  rule the native path enforces.

**The key behavioral difference**: colymba+guard **silently reverts** disallowed changes and
still returns `200` (a hook-layer constraint — the guard can't refuse the whole request from
inside `onBeforeWrite`, matching the reference `ApiFieldGuardExtension` this was productized
from); the native pipeline **rejects the whole payload**. See
[Security model](04_security-model.md#why-writeguardextension-is-mandatory) for when this
matters.

`WriteGuardExtension` scopes itself to writes running under colymba's controller
(`Controller::curr() instanceof RESTfulAPI`) — it never touches CMS writes or the native
pipeline.

## `ValueTransformer` extension point

`WriteApplicator.transformers` is an ordered, first-match-wins list of service refs implementing
`ValueTransformer` (`supports()`/`transform()`), tried before a field value is applied.
`ColorTokenTransformer` and `LinkTransformer` are the two shipped implementations, each
registered only when its optional dependency is installed (`_config/essentials.yml`,
`_config/linkfield.yml` — `Only: classexists`). A third-party integration can register its own
transformer the same way.

## `PublishOrchestrator`

Every stage transition in the module — batch op `publish`, composition element/child publish,
single-record stage actions, delete modes — routes through this one class rather than each call
site duck-typing `hasMethod('publishSingle')`. This matters concretely in
`CompositionService::publishAll()`: not every has_many child model is Versioned (e.g.
Essentials' `StatCounter` is a plain DataObject), and a duck-typed check at that call site would
diverge from the answer every other publish action already gives.

## `CompositionService`'s deferred-retry queue

Composition elements are processed in a loop: any entry whose `fields`/`relations`/`children`
contain an unresolved `{"$ref": ...}` is deferred to the next pass. This lets `$ref` order
within a request be arbitrary. Termination: if a pass makes no progress and entries remain, the
unresolved ref names are collected — `422 CIRCULAR_REF` when more than one entry is stuck
(mutual dependency), `422 UNRESOLVED_REF` for a single stuck entry (a genuinely missing alias).
