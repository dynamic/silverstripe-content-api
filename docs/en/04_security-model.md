# Security model

Three independent gates apply to every request, in order: **class-level**, **record-level**,
then (for writes) **field-level**. A request that passes all three still has to satisfy
[environment gating](#environment-gating) for population endpoints.

## Permission codes

| Code | Grants | Notes |
|---|---|---|
| `CONTENT_API_ACCESS` | Every content API endpoint | Record-level `canView`/`canEdit`/`canDelete`/`canCreate` still applies on top |
| `CONTENT_API_POPULATE` | Batch, compositions, asset uploads, page actions | Population-domain endpoints only |
| `CONTENT_API_SCHEMA` | Schema introspection | Implicitly granted to anyone with `CONTENT_API_ACCESS` |
| `VIEW_DRAFT_CONTENT` | Reading a record once its draft and live stages diverge | A core `silverstripe/versioned` permission, not module-specific — see [Service account permissions](#service-account-permissions) |

## Service account permissions

Reads default to the **draft** stage (see [Publishing & stages](10_publishing-and-stages.md)),
and `canView()` is evaluated while the current stage is draft. Core `Versioned::canViewVersioned()`
falls back to a fixed permission list — `CMS_ACCESS_LeftAndMain`, `CMS_ACCESS_CMSMain`,
`VIEW_DRAFT_CONTENT`, `CAN_DEV_BUILD` (the `non_live_permissions` config) — the moment a
record's draft and live stages differ (`stagesDiffer()`) and no extension answers the
`canViewNonLive` extend hook, and `DataObject::extendedCan()` takes the **minimum** of every
extension's answer for a hook — a `false` from this core fallback denies the read even if an
app-level `canView()` extension answers `true`.

A service account holding only `CONTENT_API_ACCESS` (this module's own documented pattern: no
real CMS login, just an app-level `canView()`/`canEdit()` grant extension) holds none of those
four codes by default. The result: the very first draft-only write (`POST batch`/compositions
with the default `publish: "none"`) makes that same record's draft **unreadable to the same
account that just wrote it** — `GET records/$Class/$ID` (draft is the default stage) 403s
`FORBIDDEN_RECORD` immediately after. A `_stage=live` read is unaffected — it bypasses this
check entirely rather than passing it, since a live-stage read never invokes the non-live
fallback in the first place.

**Grant `VIEW_DRAFT_CONTENT` alongside `CONTENT_API_ACCESS`** to any service account that reads
back a `publish: "none"` write before publishing — the natural "write draft, read back to
verify, then publish" flow batch/compositions are designed to support. It doesn't require any
CMS login capability, so the account stays API-scoped.
`sake tasks:SetupContentApiServiceAccount` provisions both grants (plus `CONTENT_API_POPULATE`
with `--populate`) in one step — see [Quick start](01_quickstart.md#3-grant-permissions-and-mint-a-token).

`CONTENT_API_ACCESS` alone satisfies only the class-level gate below — it grants nothing at the
record level. Without an app-level `canView()`/`canEdit()`/`canCreate()`/`canDelete()` grant, a
plain `CONTENT_API_ACCESS` holder still fails every write with `FORBIDDEN_RECORD`, since a
model's own `can*()` methods fall through to real CMS-login permissions (`ADMIN`,
`SITETREE_EDIT_ALL`, ...) that account was never meant to hold. See
[Grant extension](#grant-extension) below — the module ships one so projects don't have to write
it themselves.

## Grant extension

`Dynamic\ContentApi\Security\ContentApiGrantExtension` grants record-level
`canView()`/`canEdit()`/`canCreate()`/`canDelete()` to any Member holding `CONTENT_API_ACCESS`.
Apply it per class, the same pattern as `WriteGuardExtension` — the module never auto-applies it:

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
DNADesign\Elemental\Models\BaseElement:
  extensions:
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
```

Apply it to `BaseElement` too if the service account writes Elemental content, not just pages —
`BaseElement::canView()`/`canEdit()`/`canDelete()` delegate to the owning page's own check, but
`canCreate()` does not; it falls straight to `Permission::check('CMS_ACCESS', 'any', $member)`,
which a `CONTENT_API_ACCESS`-only account can never satisfy. A grant on `SiteTree` alone lets the
account edit/publish/archive pages but never create an element.

**The grant is scoped to classes that declare their own `content_api_access` (or `api_access`),
and to the verbs that declaration lists — both are load-bearing.** Since the class-level gate
below inherits and doesn't narrow anything (see [Class-level gate](#class-level-gate)), a blanket
record-level grant on every subclass would be a real privilege escalation: a project declaring
access on `Page` would let the service account write and archive every undeclared `Page`
subclass too. The extension avoids this by reading each class's access config **uninherited, and
excluding any value contributed by another extension** (`Config::UNINHERITED |
Config::EXCLUDE_EXTRA_SOURCES`) — a class only gets a grant answer if its own literal declaration
names a verb; every other subclass gets `null` and falls through to its normal permission checks,
unaffected. It then grants only the specific `can*()` hooks whose verb the class's own declaration
lists — a class listing `action` but not `DELETE` never gets `canDelete()` granted, so [record
actions' delete/action split](#record-level-gate) (`archive` needs `delete`, not `action`) still
holds.

Two caveats worth knowing:

- **The grant is per concrete class, not inherited via the record-level check either.** A project
  following the documented "`Image` inherits `File`'s `api_access`" convention gets `canCreate()`
  on an uploaded `Image` (checked against `File` itself), but a later `canEdit()`/`canDelete()` on
  that record checks `Image`'s own uninherited declaration — empty, if `Image` never declares its
  own — and falls through to `FILE_EDIT_ALL`, which a `CONTENT_API_ACCESS`-only account doesn't
  hold. Declare access on every concrete class a service account needs to write, not just the
  ancestor it inherits HTTP exposure from.
- **The uninherited check doesn't require the class to be registered in `ClassRegistry`'s exposure
  map.** `content_api_access` is module-specific, so only a project sets it deliberately; the
  legacy `api_access` key is one third-party code can self-declare for unrelated reasons, so a
  vendor class that happens to declare it under a grant-applied ancestor gets a grant even if the
  content API itself could never route to it. This mirrors the class-level gate's own existing
  trust model for `api_access` (this extension is strictly narrower — uninherited vs inherited —
  not broader), consistent with the module's design principle that a class's own `can*()` methods
  are the real safety boundary, not map membership.

Every hook returns `true` or `null`, never `false`: `DataObject::extendedCan()` takes the
minimum of every extension's answer, so a `false` here would deny that permission for every
other member and extension too, sitewide — including real CMS editors.

**A draft-read 403 after applying this extension is not a missing grant** — see
[Service account permissions](#service-account-permissions) above. `canView()` alone does not
make a draft-only record readable: `Versioned::canViewVersioned()` independently vetoes once
draft and live diverge, and that veto participates in the same `extendedCan()` minimum. `VIEW_DRAFT_CONTENT`
is what satisfies it, and `SetupContentApiServiceAccount` already grants it alongside
`CONTENT_API_ACCESS`. Don't widen this extension to work around that 403; check the account
holds `VIEW_DRAFT_CONTENT` instead.

Similarly, **`canEdit()`'s grant is what clears `Versioned::canDelete()`'s veto on an
already-published record**, not `canDelete()`'s own answer: `Versioned::canDelete()` vetoes
unless `canUnpublish()` succeeds, and `canUnpublish()` falls through to `canPublish()` falls
through to `canEdit()`. A class declaring only the `delete` verb (no `update`/`action`) can
archive a draft-only record but not a published one.

**Branch note:** that veto does not exist on branch `1`'s `silverstripe/versioned` (confirmed
against a real SS5.2 install running `2.4.x-dev`: `Versioned` has no `canDelete()` override at
all there — only a deprecated `canArchive()` whose own docblock says to use `canDelete()` instead
on the version branch `1` depends on). A class declaring only `delete` on branch `1` can archive
both a draft-only record and an already-published one.

### The `extendedCan()` contract this extension depends on

`ContentApiGrantExtension` only works through `DataObject::extendedCan()` — the standard
SilverStripe mechanism every extension's `can*()` hook goes through. **Any class registered in
`api_access`/`content_api_access` that this extension is applied to must route its own
`can*()` methods through `extendedCan()`, exactly as `SiteTree`'s own `canView()`/`canEdit()`/
`canCreate()`/`canDelete()` do (each starts with `$extended = $this->extendedCan('canEdit',
$member); if ($extended !== null) return $extended;`), or the extension's hook is never
consulted at all.**

This is not hypothetical. Hit for real on a client project: FoxyStripe's `ProductPage` hard-
overrides `canEdit()`/`canDelete()`/`canCreate()`/`canPublish()` directly and never calls
`extendedCan()` — unlike `SiteTree`'s own implementations. The result was a plain 403 on every
write to that class, with no error identifying the cause, no log line pointing at the extension
being bypassed, and nothing in the module's diagnostics to say "is this class's `can*()` chain
actually reachable by my extensions" — until now (see below). This fails *closed* in that
concrete case (writes just don't work), but the same gap could just as easily fail *open* on a
class whose override happens to return `true` unconditionally for some unrelated reason,
granting the API access to a class no `api_access` config ever authorized — the module would
never be in the loop either way.

**Diagnostic task:** `sake tasks:CheckGrantExtensionReachability` flags every class carrying
`ContentApiGrantExtension` whose own declared verb has no resolvable `can*()` method with a
visible `extendedCan()` call in its source. It's a reflection-based heuristic (reads the
resolved method's source text for the literal string, doesn't execute anything) — see
`Tasks\Support\GrantExtensionReachabilityChecker`'s own docblock for exactly what that can and
can't catch (a call routed through an intermediate helper method won't be found, for instance).
Run it after applying `ContentApiGrantExtension` to a new class, and again after upgrading a
third-party module that might have changed a `can*()` override.

## Class-level gate

`PermissionPolicy::checkClassAccess()` requires `CONTENT_API_ACCESS` **and** that the class
exposes the requested verb via `api_access` / `content_api_access` (see
[Configuration reference](02_configuration.md#per-exposed-class-config)).

Two things about this gate are easy to assume and both are wrong:

- **It is not deny-by-default for a subclass.** `api_access`/`content_api_access` are ordinary
  SilverStripe config and therefore *inherit* — a class with no configured ancestor is denied by
  default, but any subclass of an exposed class inherits its verbs unless it declares its own.
  Declaring `content_api_access: 'GET,POST,PUT,DELETE,action'` on `Page` exposes every `Page`
  subclass in the project — `ErrorPage`, `RedirectorPage`, `UserDefinedForm`, commerce page
  types — to that same verb list, whether or not any of them appear in `content-api.yml`.
- **It is not checked against "the record's concrete class" in the sense of narrowing to it.**
  `RecordsHandler::fetchRecord()` resolves a record via `DataObject::get_by_id($resolvedClass,
  $id)`, which returns whatever concrete class that row's `ClassName` actually is — so a
  subclass is reachable under any mapped *ancestor* ref (`records/Page/$id`, not just
  `records/ErrorPage/$id`). The class gate is evaluated against that concrete class, which is
  accurate, but because the gate inherits (previous point), it doesn't narrow anything for an
  undeclared subclass — it just answers the question the same way its declaring ancestor would.

The practical consequence: the class-level gate alone never narrows a write to only the classes
a project listed. The record-level `can*()` answer — your model's own permission logic, or the
[grant extension](#grant-extension) above — is the only gate that can.

Class-level checks deliberately never call `can*()` on an unhydrated singleton — a lesson
carried over from an earlier tenant-scoped `can*()` implementation that 403'd on records that
were never loaded (see the class doc on `PermissionPolicy`). Record checks (below) always run
on a real, loaded record.

**A payload write's `publish` key requires the class-level `action` verb, not just `update`
(#114).** `RecordWriter::write()` (`upsert()`/`update()`, and therefore every batch op and
composition field write that routes through it) checks `update`/`create`, but `update` and
`action` are independently configurable — a class granting `update` while withholding `action`
previously had its root record published anyway, including `"publish": "subtree"` turning one
authorized field write into a whole-tree publish. Checked once, class-level, whenever the
payload's `publish` key isn't `none`, on a `Versioned` class (matching
`PublishOrchestrator::publish()`'s own no-op for a non-versioned record — a class that can never
be published has nothing here for `action` to gate). `PageHandler::convert()` and
`CompositionService::convertPage()` have the analogous gap closed the same way: both now check
the *target* class's `update` verb unconditionally, plus `action` whenever the request's publish
mode isn't `none` — previously only the *pre-conversion* record's `update` verb was ever checked.

`CompositionService::compose()` has a third, non-conversion path to the same gap:
`publishAll()` calls `$page->publishRecursive()` directly (not via `RecordWriter` or
`PublishOrchestrator`'s own authorization), reachable with no `page.convertTo` in the payload at
all — the page's own field write, if any, never carries a `publish` key, so `RecordWriter`'s
check above never fires for it either. `compose()` now checks the page's own `action` verb
before `publishAll()` runs, whenever the composition's own top-level `publish` is `recursive`.

**#119/#168 closed the last gap in this family.** `publishAll()` used to also publish the
composition's area and every element (and element child) via
`PublishOrchestrator::publish($record, 'single', $member)` — `single` mode performs no
authorization at all, and nothing upstream checked `action` for those classes either, since
element writes always pass `"publish": "none"` explicitly. The identical cascade, with the
identical gap, also existed in `PageHandler::applyTemplate()`'s own `publishSingle()` calls on the
area and its elements. Both call sites now route through
`PublishOrchestrator::publishOwnedTree()` (the primitive the `owns` publish mode itself
dispatches to — see [Publishing & stages](10_publishing-and-stages.md#publish-modes)) instead of
their own hand-rolled loops: every area/element/child is now authorization-checked (class
`action` verb + `canEdit()`) before anything is written, the whole cascade refusing on the first
one the caller can't publish rather than leaving earlier ones live with no way to undo it.

**Behavior change**: any class in the walked `$owns` cascade — not just elements — granting
`create`/`update` but withholding `action` now gets `403 FORBIDDEN_CLASS` on a composition or
apply-template publish, where it previously published silently. This reaches further than
elements/areas: `File`/`Image` are versioned, so an owned image relation (a common pattern for
image-bearing elements) is checked too, and asset classes are the ones most likely to be
configured read/create-only today. See
[Publishing & stages](10_publishing-and-stages.md#publish-modes) for the full note.

**Unpublish's `owns` mode goes the other way (#119): it excludes `File`/`Image` rather than
authorization-checking them.** It excludes them from the walk entirely, rather than checking and cascading to them
(see [Unpublish modes](10_publishing-and-stages.md#unpublish-modes)) — an owned asset is never
unpublished by this cascade, whether or not a project's exposure config grants `action` on it.
Practical effect: adopting `unpublish`'s `owns` mode never requires the `File`/`Image` `action`
grant the publish side does, and shortens the grant checklist for a project that only needs the
unpublish half.

## Record-level gate

`PermissionPolicy::checkRecordAccess()` calls the model's own permission method for the verb:
`read`→`canView()`, `update`/`action`→`canEdit()`, `delete`→`canDelete()`. This is your model's
existing ACL — the content API adds nothing here beyond calling it.

The `publish`/`unpublish`/`archive` stage actions (`POST records/$Class/$ID/{action}`) are not
all "edit" operations: `publish` and `unpublish` use the `action` verb (`canEdit()`), but
`archive` — a soft-delete from both Draft and Live — uses the `delete` verb (`canDelete()`), at
**both** gates. Granting `action` in `api_access` to let a consumer publish/unpublish does
**not** also grant archive; that requires `delete` to also be listed in the class's `api_access`
**and** `canDelete()` to independently allow it at the record level.

**`unpublish` with `{"force": true}` additionally requires `delete` — but only where forcing can
actually do anything (#80).** Forcing bypasses the descendant-cascade guard (see [Publishing and
stages](10_publishing-and-stages.md)), and where that bypass is real, the cascade it uncovers is
delete-shaped — the same live-subtree loss `archive` produces. But per #89, the guard itself only
ever has something to bypass on a `SiteTree` class with `enforce_strict_hierarchy` enabled; on
every other class `force: true` is already a no-op, so this extra `delete` requirement doesn't
apply there either — demanding a verb for a bypass that was never going to cascade anything would
itself be a breaking change for a client that defensively always sends `force: true`. Plain,
non-forced `unpublish` always needs only `action`. Where it does apply, `force: true` needs
`action` (unpublish's base verb) **and** `delete`, checked at both the class and record level —
the `delete` requirement mirrors how the batch `delete` op (`RecordWriter::delete()`) has always
been gated on `delete` alone, regardless of `mode`.
`PublishOrchestrator::forceCouldStrandDescendants($className)` is the one place this scoping is
decided — both the guard and this verb gate call it, so they can't drift apart.

**Create** is different: `checkCreateAccess()` calls `canCreate($member, $context)` with a
`$context` array hydrated from the payload's has_one keys (`buildCreateContext()`), because a
tenant-scoped `canCreate()` often needs the *parent* record to decide, and that parent doesn't
exist yet as a real record. Hydration:

- Accepts both `Relation` and `RelationID` payload keys.
- A relation the client can't even write is skipped before resolution (matching the
  writability check, not a separate rule).
- A **polymorphic** has_one requires the same `{"class": ..., "id"/"externalId": ...}` shape as
  a write (see [Write payloads](06_write-payloads.md#polymorphic-has_one)) — a malformed or
  unresolvable hint here is rejected the same way the write itself would reject it, rather than
  silently omitting the relation from context (a fail-open gap this design specifically closes).
- List reads use a lighter `canViewRecord()` check per row to filter without throwing (see
  [Endpoint reference](05_endpoint-reference.md#get-recordsclassref)).

## Field-level gate: write policies

`WriteApplicator::isFieldWritable()` is the single source of truth for field writability on
**every** write surface — the module's native pipeline (batch, compositions, single-record
update) and, via `WriteGuardExtension`, colymba's own `/api` write path.

- **`guarded`** (global default `policy`): all db fields and has_one relations are writable
  except the `protected_fields` denylist (global + per-class `api_protected_fields`). has_many /
  many_many writes require the relation to be listed in `api_writable_relations` regardless of
  policy.
- **`allowlist`**: only fields in `api_writable_fields` (or relations in
  `api_writable_relations`) are writable. A class enters allowlist mode either by explicit
  `api_write_policy: allowlist`, **or implicitly the moment it declares a non-empty
  `api_writable_fields`** — even under the `guarded` global policy. There is no configuration
  where `api_writable_fields` is set but silently has no effect.
- The protected-field denylist is an absolute floor: it applies under both policies, and — see
  below — even to the trusted internal-fields channel.

A polymorphic has_one's FK and companion `{Name}Class` column are gated **as a pair** — both
must independently pass `isFieldWritable()` (`isPolymorphicRelationWritable()`), never one
implicitly riding on the other's writability.

## The trusted internal-fields channel

A small set of server-derived structural fields — currently a composition element's `ParentID`
(the ElementalArea FK) — are written by in-process population machinery
(`CompositionService::processElement()`) through a separate, trusted parameter
(`WriteApplicator::applyFields()`'s `$internalFields` argument), never through the client's
`fields` payload.

- Bypasses the `api_writable_fields` allowlist check.
- **Never** bypasses the `protected_fields`/`api_protected_fields` denylist — that floor is
  absolute.
- Always wins over a same-named key in the untrusted `fields` payload, so a client can't smuggle
  its own value for a structural field under that name.
- Only in-process module code populates it. Request-derived values must never flow into this
  parameter — it exists specifically so `ParentID` never has to appear in
  `api_writable_fields`, which would also expose it on the untrusted colymba PUT surface.
- `PermissionPolicy::buildCreateContext()` accepts the same `$internalFields` parameter (kept
  separate from `$fields`, not pre-merged by the caller) so a relation set only via this channel
  is still hydrated into a tenant-scoped `canCreate()`'s context.

## Why `WriteGuardExtension` is mandatory

Colymba's native write path (`DefaultQueryHandler::updateModel()`) applies **every key** in the
JSON payload with no writability check at all — a GET-then-PUT-verbatim round trip can detach
`_many` relations (colymba applies them via `removeAll()` + `add()`). `WriteGuardExtension`
hooks `onBeforeDeserialize`/`onBeforeWrite` to enforce the same `api_writable_fields` /
`api_protected_fields` / `api_writable_relations` rules on that surface.

**The two write surfaces differ in failure mode**, and this is the reason to prefer batch/
compositions when correctness matters more than colymba API compatibility:

- **Colymba `/api` + `WriteGuardExtension`**: disallowed fields are **silently reverted** and
  unlisted relations are stripped — the request still returns `200`. This matches the reference
  `ApiFieldGuardExtension` pattern and is a hook-layer constraint (colymba's deserializer has
  already applied the field by the time the guard can act).
- **This module's own write pipeline** (batch/compositions/single-record writes): a disallowed
  field **rejects the entire payload** with a structured `READONLY_FIELD`/`UNKNOWN_FIELD` error
  — nothing is applied. See [Write payloads](06_write-payloads.md).

Never grant write verbs (`POST,PUT`) in `api_access` without `WriteGuardExtension` applied to
the same class.

## Environment gating

Population-domain endpoints (batch, compositions, page actions, asset writes) additionally
require `EnvironmentGate::checkPopulationAllowed()` to pass:

- Allowed by default in `dev` and `test` environments only (`population_enabled_environments`).
- `SS_CONTENT_API_ALLOW_POPULATE=1` (parsed with `FILTER_VALIDATE_BOOLEAN`) overrides for
  deliberate UAT/staging runs.
- Denied requests return `403 ENV_FORBIDDEN`, with `details` naming the current environment,
  the env var, and the configured `population_enabled_environments` (#126) — a caller already
  holding `CONTENT_API_POPULATE` (every call site checks that first) can tell this apart from an
  ACL failure without parsing message text.

## Composition child ACL and identity scoping

A page composition's `children` (has_many, e.g. `Panels` under an element) are not exempted by
the parent's `CONTENT_API_POPULATE` grant — each child independently runs the same class-level
gate and `canCreate()`/`canEdit()` check as any other written record. A grant to populate does
not imply a grant to write an arbitrary has_many-target class.

A child's `externalId` lookup is **scoped to the owning element's own relation list** — a
composition can never adopt or re-parent a child record that currently belongs to a different
element, even if the external id collides globally. See
[Page compositions](08_page-compositions.md#children).
