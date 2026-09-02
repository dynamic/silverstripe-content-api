# Configuration reference

Every configurable option the module reads, grouped by the class that owns it. Values are
`private static $config` unless noted; set them via YAML config (`Config::modify()->set(...)`
or a `_config/*.yml` document) same as any other SilverStripe config.

## Colymba hardening (set by `_config/config.yml`)

The module ships hardened defaults for the colymba `/api` surface on install — colymba's own
defaults are open (`authentication_policy` null, CORS `*`). Projects may relax these
deliberately.

| Config key | Class | Default (this module) | Purpose |
|---|---|---|---|
| `authentication_policy` | `Colymba\RESTfulAPI\RESTfulAPI` | `true` | Requires a token on every `/api` call, including GET |
| `access_control_policy` | `Colymba\RESTfulAPI\RESTfulAPI` | `'ACL_CHECK_CONFIG_AND_MODEL'` | Checks both config verbs and the model's `can*()` methods |
| `cors.Enabled` | `Colymba\RESTfulAPI\RESTfulAPI` | `false` | CORS off — the API is designed for server-to-server/agent callers |
| `tokenLife` | `Colymba\RESTfulAPI\Authenticators\TokenAuthenticator` | `604800` (7 days, seconds) | Token lifetime. Raise for long-lived service accounts (e.g. `31536000` for a year) rather than relying on refresh |
| `autoRefreshLifetime` | `Colymba\RESTfulAPI\Authenticators\TokenAuthenticator` | `false` | No activity auto-refresh — a fixed, predictable, revocable lifetime |

`/content-api/v1` itself has no CORS surface — it emits no `Access-Control-*` headers and handles
no `OPTIONS` preflight. That's a deliberate position, not an oversight: the endpoint is designed
for server-to-server/agent callers, not browsers. `cors.Enabled` above is colymba's own `/api`
surface only.

## ClassRegistry

`Dynamic\ContentApi\Registry\ClassRegistry`

| Key | Default | Purpose |
|---|---|---|
| `models` | `[]` | Content-api-only short-ref → FQCN map. Merged **over** colymba's `DefaultQueryHandler.models` (this module's entries win per key on conflict) |
| `discovery_roots` | `[]` | FQCNs (e.g. `BaseElement::class`, `SiteTree::class`) to auto-map every concrete subclass of, without a hand-written `models:` entry per class. Off by default — entirely opt-in |
| `discovery_exclude` | `[]` | Additional FQCNs (and their subclasses) to skip during discovery, on top of the mandatory denylist (`Member`, `Group`, `Permission`, etc. — not configurable, can't be relaxed away) |
| `discovery_write_policy` | `'off'\|'read'` | `'off'` | Verbs granted to a class reached **only** via discovery, carrying no `api_access` of its own. `'read'` grants read-only. There is no write-granting value — a discovered class's writable fields would have to be inferred, the same mistake `api_writable_fields` hoisting already caused once (#27); writes always require an explicit `api_writable_fields` on the class |

`ClassRegistry::VERBS` (not configurable, informational): `read`, `create`, `update`, `delete`,
`action`. Colymba-style `api_access: 'GET,POST'` values are mapped `GET→read`, `POST→create`,
`PUT`/`PATCH→update`, `DELETE→delete`; a bare verb name (`action`) is also accepted directly.

### Auto-discovery

A class with its own explicit `api_access`/`content_api_access` always uses that, whether or not
it's also reachable via `discovery_roots` — discovery only ever fills the gap when neither is set.
Runtime safety doesn't come from the map itself: every operation still passes through
`canView()`/`canEdit()` and `WriteApplicator::isFieldWritable()`'s denylist regardless of how a
class reached the map, which is what makes discovery safe to automate for reads.

Discovery does **not** auto-apply `ExternalIdentifierExtension` — confirmed by a live test that
adding an extension at request time doesn't reliably affect the schema `dev/build` already
computed. A discovered class is read-addressable by numeric id only until a project applies the
extension to it explicitly via normal YAML (a one-line addition, not the full exposure block).

```yaml
Dynamic\ContentApi\Registry\ClassRegistry:
  discovery_roots:
    - DNADesign\Elemental\Models\BaseElement
    - SilverStripe\CMS\Model\SiteTree
  discovery_write_policy: 'read'
```

## Per-exposed-class config

Set directly on each DataObject class you expose — not on the module. These are read by
`ClassRegistry`, `WriteApplicator`, `RecordSerializer`, and `SchemaService`.

| Key | Type | Default | Purpose |
|---|---|---|---|
| `api_access` | `bool\|string` | unset (not exposed) | `true` = all verbs; or a CSV of tokens mapped to read/create/update/delete/action — `GET`/`POST`/`PUT`/`DELETE` map to their HTTP-method namesakes, but `action` (publish/unpublish/archive) has **no HTTP method of its own** and must be listed explicitly as a bare `action` token (e.g. `'GET,POST,PUT,action'`) — a CSV of only HTTP-method verbs looks complete but can never publish a write through this API (#198), with no error saying so. Declaring this (or `content_api_access`) is what exposes the class at all — deny-by-default otherwise. **Inherited**, like any SilverStripe config: a subclass with no `api_access`/`content_api_access` of its own still exposes its ancestor's verbs (see [Class-level gate](04_security-model.md#class-level-gate)) |
| `content_api_access` | `bool\|string` | unset | Same shape as `api_access`; **wins** when both are set. Lets a class have different colymba-vs-content-api exposure. Also inherited — see above |
| `api_write_policy` | `'guarded'\|'allowlist'` | unset (falls back to `WriteApplicator.policy`) | Per-class override of the global write policy |
| `api_writable_fields` | `string[]` | `[]` | Allowlist of client-writable db field / has_one relation names. **A non-empty array here puts the class into allowlist mode even under the `guarded` global policy** — declaring the allowlist is itself the opt-in, with no configuration that sets it and has it silently ignored |
| `api_protected_fields` | `string[]` | `[]` | Per-class denylist, merged with the global `WriteApplicator.protected_fields`. Always wins over the allowlist, and still applies to the trusted internal-fields write channel |
| `api_writable_relations` | `string[]` | `[]` | Allowlist of writable has_many/many_many relation names (a separate gate from `api_writable_fields`, which only covers db fields and has_one) |
| `api_unknown_fields` | `'strict'\|'lenient'` | unset (falls back to `WriteApplicator.unknown_fields`) | Per-class override: `strict` rejects an unrecognized payload key with `UNKNOWN_FIELD`; `lenient` warns and continues |
| `api_fields` | `string[]` | unset (all fields serialized) | `RecordSerializer` output whitelist. Entries may be db fields, relation names, **or `getFoo()` getter-backed properties** (getters are only honored when `api_fields` is explicitly set) |
| `api_computed_fields` | `string[]\|array<string,?string>` | `[]` | Schema-only honesty flag: fields recomputed by the model itself (e.g. an `onBeforeWrite` trap) — a write lands, then the model overwrites it in the same request. Surfaced as `computed: true` (+ optional `note`) in `SchemaService::classSchema()`. **Advisory only** — does not affect `writable`; pair with `api_protected_fields` to also reject the write |
| `api_import_owned_fields` | `string[]\|array<string,?string>` | `[]` | Schema-only honesty flag: fields owned by an external import/feed that will overwrite a client's write on its next sync. Surfaced as `importOwned: true` (+ optional `note`). Same advisory-only caveat as `api_computed_fields` |

See [Security model](04_security-model.md) for how these combine into the guarded/allowlist
decision, and [Write payloads](06_write-payloads.md) for the payload shapes they gate.

## EnvironmentGate

`Dynamic\ContentApi\Security\EnvironmentGate`

| Key | Default | Purpose |
|---|---|---|
| `population_enabled_environments` | `['dev', 'test']` | Environments where batch/composition/page-action/asset-write endpoints are allowed |

Env override: `SS_CONTENT_API_ALLOW_POPULATE` — parsed with `FILTER_VALIDATE_BOOLEAN` (not bare
PHP truthiness, so the literal string `"false"` is correctly treated as false). Set to `1` /
`true` to bypass the gate deliberately (e.g. a UAT population run). Unset or unrecognized values
default to `false`.

**Before a project's first population write against a `live`-type target, confirm this variable
is set.** A `dev`/`test` rehearsal never exercises this gate at all — the environment check above
only fires outside `population_enabled_environments` — so a clean, repeated local rehearsal gives
no signal whatsoever that a real target will need `SS_CONTENT_API_ALLOW_POPULATE=1`. The gate
also logs a warning server-side (not just the `403 ENV_FORBIDDEN` response) the moment it blocks
a call, specifically so this is visible in a deploy/ops log even when nothing is watching the API
response itself. The response itself also carries structured `details` (`environment`, `envVar`,
`populationEnabledEnvironments`) so a caller that isn't a human reading the message text — an
agent driving this API on someone's behalf — can tell this apart from an ACL failure without
being able to read the site's own `.env` (#126).

**Security note: `population_enabled_environments` cannot be narrowed by a project's own YAML
override.** SilverStripe merges array-typed config additively across every source — a project
YAML document setting this to `[]` (or any subset of the module's `['dev', 'test']` default) does
not subtract from that default; the vendor value survives untouched, and the YAML looks correct on
inspection. Confirmed on a real project: a config file was added specifically as independent
defense-in-depth, its own comment claiming it would keep the population write surface blocked on
pre-prod even if the primary gate were ever loosened — it did not, found only hours before a
merge and deploy by a pre-merge code review (#199). To actually restrict this value below the
module default, use `Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', [...])`
in `_config.php` — a hard set, not a YAML merge. Applies to any array-typed config knob this module
exposes for security-relevant gating, not just this one.

## RequestLogger

`Dynamic\ContentApi\Logging\RequestLogger`

| Key | Default | Purpose |
|---|---|---|
| `enabled_environments` | `[]` | Environments where an opt-in structured server-side request log is emitted (#207) |

Off everywhere by default — this is a write-heavy content API on client sites, and logging every
request isn't a tax any project asked for. One `info`-level entry per `content-api/v1` request
(both success and error responses), covering the endpoint, HTTP method, `ClassRef`/action route
params, HTTP status, machine-readable error code (if any), duration, response byte size, and the
authenticated member's ID (never email — see [Security model](04_security-model.md)).

Env override: `SS_CONTENT_API_REQUEST_LOG=1` forces logging on regardless of
`enabled_environments`, the same `FILTER_VALIDATE_BOOLEAN` parsing as `EnvironmentGate` above.
**This override is force-ON only — there is no force-OFF direction.** `Environment::getEnv()`
returns PHP `false` both when a var was never set and when it was explicitly set to a value that
parses falsy, so a bidirectional override can't reliably tell "unset" apart from "explicitly
forced off" — the var would look identical either way. Turning logging off in an environment
`enabled_environments` already lists is a config change, not an env var: per the #199 note above,
use `Config::modify()->set(RequestLogger::class, 'enabled_environments', [...])` in `_config.php`
to narrow it — a project's own YAML override can only widen this array, never narrow it.

Only covers this module's own `content-api/v1` controller. Colymba's generic `/api` CRUD surface
is a separate controller this module doesn't own, and a `content-api/v1` URL matching no declared
route is rejected before the logging hook ever runs — neither is logged. See
[Architecture](14_architecture.md#request-lifecycle).

A `POST batch` request with per-operation failures still returns HTTP 200 (see
[Batch operations](07_batch-operations.md)) — the log's `opFailures` field surfaces that count
separately from the HTTP status, so a partially-failed batch doesn't read as a clean success in
the log the way it does on the wire.

## WriteApplicator

`Dynamic\ContentApi\Write\WriteApplicator`

| Key | Default | Purpose |
|---|---|---|
| `policy` | `'guarded'` | Global write policy: `guarded` (protected-field denylist, everything else writable) or `allowlist` (only `api_writable_fields` entries writable) |
| `protected_fields` | `ID, ClassName, Created, LastEdited, Version, Password, Salt, PasswordEncryption, AutoLoginHash, TempIDHash, ApiToken, ApiTokenExpire` | Global denylist — never writable regardless of policy, merged with per-class `api_protected_fields`. This floor also applies to the trusted internal-fields channel |
| `unknown_fields` | `'strict'` | Global default for unrecognized payload keys, overridable per class via `api_unknown_fields` |
| `transformers` | `[]` | Ordered list of `ValueTransformer` service refs, first-match-wins. The essentials/linkfield config docs (below) append to this list when their integration is installed |

This is the single source of truth for field writability on **every** write surface — the
module's native pipeline and, via `WriteGuardExtension`, colymba's own `/api` write path.

## RecordsHandler

`Dynamic\ContentApi\Control\Handlers\RecordsHandler`

| Key | Default | Purpose |
|---|---|---|
| `default_limit` | `50` | List-read page size when `limit` isn't specified |
| `max_limit` | `500` | Hard cap on `limit`, regardless of what the caller requests |
| `allowed_filter_modifiers` | `ExactMatch, PartialMatch, StartsWith, EndsWith, GreaterThan, GreaterThanOrEqual, LessThan, LessThanOrEqual, not, nocase, case` | Modifiers accepted after `Field__` in a list-read query string |
| `reserved_query_params` | `sort, limit, offset, _stage, stage, token, url, flush, flushtoken, ajax` | Query keys never treated as a field filter |

## RecordSerializer

`Dynamic\ContentApi\Serialize\RecordSerializer`

| Key | Default | Purpose |
|---|---|---|
| `hidden_fields` | `Password, Salt, PasswordEncryption, PasswordExpiry, AutoLoginHash, AutoLoginExpired, TempIDHash, TempIDExpired, SessionData, ShareTokenSalt, ApiToken, ApiTokenExpire` | Fields never serialized regardless of `api_fields` |

## ExternalIdResolver

`Dynamic\ContentApi\Identity\ExternalIdResolver`

| Key | Default | Purpose |
|---|---|---|
| `external_id_field` | `'FixtureIdentifier'` | DB column used as the API's idempotency key. Defaults to the fixtures-recipe column name so pre-populated sites are addressable as-is |

## SchemaService

`Dynamic\ContentApi\Schema\SchemaService`

| Key | Default | Purpose |
|---|---|---|
| `field_tokens` | `[]` | Field-name → token-DSL hint surfaced in `GET schema/$ClassRef` (the essentials integration sets `BackgroundColor: palette`, `ButtonColor: button` — see below) |

## ColorTokenTransformer

`Dynamic\ContentApi\Write\Transformers\ColorTokenTransformer`

| Key | Default | Purpose |
|---|---|---|
| `token_fields` | `['BackgroundColor', 'ButtonColor']` | Fields eligible for `$palette(N)` / `$button(N, Label)` resolution |

Registered only when `dynamic/silverstripe-essentials-tools`'s
`ColorConfigurationProvider` class exists (`_config/essentials.yml`, `Only: classexists`).

## LinkTransformer

`Dynamic\ContentApi\Write\Transformers\LinkTransformer`

| Key | Default | Purpose |
|---|---|---|
| `type_map` | `ExternalLink, SiteTreeLink, EmailLink, PhoneLink, FileLink` → their `SilverStripe\LinkField\Models\*` FQCNs | Payload `type` values accepted on a structured link write |

Registered only when `silverstripe/linkfield`'s `Link` class exists (`_config/linkfield.yml`).

## ExternalIdentifierExtension

`Dynamic\ContentApi\Identity\ExternalIdentifierExtension`. Not configurable, but adds a fixed schema to any class it's applied to: `db: { FixtureIdentifier:
Varchar(100) }`, indexed. Deliberately byte-identical to the fixtures recipe's own column spec so
the two merge to one column on a site using both.

## ContentApiGrantExtension

`Dynamic\ContentApi\Security\ContentApiGrantExtension`. Not configurable — its opt-in is the
class's own `api_access`/`content_api_access` declaration, not a separate config key. Apply it
per class, like `ExternalIdentifierExtension` and `WriteGuardExtension`:

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
```

Grants record-level `canView()`/`canEdit()`/`canCreate()`/`canDelete()` to any Member holding
`CONTENT_API_ACCESS`, scoped to classes that declare their own (uninherited) `api_access`/
`content_api_access`, and only for the specific verbs that declaration lists — see
[Grant extension](04_security-model.md#grant-extension) for the full detail, including why the
uninherited scoping is load-bearing and not optional.

## Permission codes

Not YAML config, but the security surface every gate above ultimately checks — see
[`ContentApiPermissions`](../../src/Security/ContentApiPermissions.php) and
[Security model](04_security-model.md):

| Constant | Code | Grants |
|---|---|---|
| `ContentApiPermissions::ACCESS` | `CONTENT_API_ACCESS` | Every content API endpoint. **Record-level `can*()` still applies on top and is not implied** — see [ContentApiGrantExtension](#contentapigrantextension) for the module's own app-level grant |
| `ContentApiPermissions::POPULATE` | `CONTENT_API_POPULATE` | Batch, compositions, asset uploads, page actions |
| `ContentApiPermissions::SCHEMA` | `CONTENT_API_SCHEMA` | Schema introspection (implicitly granted to anyone with `ACCESS`) |
