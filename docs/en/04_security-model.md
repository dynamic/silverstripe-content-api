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

## Class-level gate

`PermissionPolicy::checkClassAccess()` requires `CONTENT_API_ACCESS` **and** that the class
exposes the requested verb via `api_access` / `content_api_access` (see
[Configuration reference](02_configuration.md#per-exposed-class-config)) — deny-by-default,
checked against the record's **concrete** class, so a subclass may narrow inherited access.

Class-level checks deliberately never call `can*()` on an unhydrated singleton — a lesson
carried over from an earlier tenant-scoped `can*()` implementation that 403'd on records that
were never loaded (see the class doc on `PermissionPolicy`). Record checks (below) always run
on a real, loaded record.

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
- Denied requests return `403 ENV_FORBIDDEN`.

## Composition child ACL and identity scoping

A page composition's `children` (has_many, e.g. `Panels` under an element) are not exempted by
the parent's `CONTENT_API_POPULATE` grant — each child independently runs the same class-level
gate and `canCreate()`/`canEdit()` check as any other written record. A grant to populate does
not imply a grant to write an arbitrary has_many-target class.

A child's `externalId` lookup is **scoped to the owning element's own relation list** — a
composition can never adopt or re-parent a child record that currently belongs to a different
element, even if the external id collides globally. See
[Page compositions](08_page-compositions.md#children).
