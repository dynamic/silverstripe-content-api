# SilverStripe Content API

Content population layer for SilverStripe 6, **built on
[colymba/silverstripe-restfulapi](https://github.com/colymba/silverstripe-restfulapi)**
(the silverstripeltd-maintained SS6 line). The dependency provides token authentication
and generic REST CRUD at `/api`; this module adds everything programmatic content
population needs on top: atomic page compositions, batch operations with per-operation
results, asset ingestion, stage-aware reads and publish actions, schema introspection,
and field-level write guarding for the generic surface.

One token, one models map, two cooperating surfaces:

| Surface | Route | Provides |
|---|---|---|
| colymba RESTfulAPI | `/api` | `GET/POST/PUT/DELETE api/$Model(/$ID)`, `api/auth/login\|logout`, token auth |
| this module | `/content-api/v1` | stage-aware GET (`_stage`, `ext:`), publish/unpublish/archive actions, batch, compositions, assets, pages, schema, `GET auth/session` |

Every endpoint maps 1:1 onto a future MCP tool (`schema/endpoints.json` holds the tool
definitions). The module is the API-driven successor to Populate-YAML-fixture workflows —
see [docs/en/migrating-from-fixtures.md](docs/en/migrating-from-fixtures.md).

## Requirements

- SilverStripe ^6, PHP ^8.3
- `colymba/silverstripe-restfulapi` (silverstripeltd `feature/cms-6-compatibility` branch)

Optional integrations (feature-gated at runtime; endpoints answer `501
FEATURE_UNAVAILABLE` when absent): `dnadesign/silverstripe-elemental` (compositions),
`silverstripe/linkfield` (link payloads), `dynamic/silverstripe-essentials-tools`
(`$palette()`/`$button()` color tokens), `dynamic/silverstripe-elemental-templates`
(apply-template).

## Installation

The colymba dependency has no Packagist release for SS6, so your **project root**
composer.json needs the VCS entry (composer ignores a dependency's own `repositories`):

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/silverstripeltd/silverstripe-restfulapi" }
]
```

```bash
composer require dynamic/silverstripe-content-api
```

### Upgrading from 1.0.x

1.1.0 replaced the module's own auth with colymba's:

- Member columns change: `ContentApiTokenHash`/`ContentApiTokenExpire` are abandoned
  (orphan columns; drop manually if you care) — colymba's `ApiToken`/`ApiTokenExpire`
  are created on `dev/build`. **Hashed 1.0.x tokens cannot be carried over — re-mint
  every service account** (`sake tasks:MintContentApiToken --email=…`).
- Removed endpoints → replacements: `POST records/$Class` → `POST api/$Class` or a batch
  `create`/`upsert` op; `PATCH records/$Class/$ID` → `PUT api/$Class/$ID` or a batch
  `update` op; `DELETE records/$Class/$ID` → `DELETE api/$Class/$ID` (hard delete!) or a
  batch `delete` op / archive stage action. `POST auth/login|logout|refresh` →
  `api/auth/login|logout` (email/pwd request vars) + `autoRefreshLifetime`.
- Injector note: `RecordsWriteHandler` was replaced by `RecordActionsHandler`.

## Quick start

1. **Expose classes** — one map drives both surfaces (deny-by-default; a class must be
   mapped AND granted `api_access`):

```yml
Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler:
  models:
    BlockPage: Dynamic\Base\Page\BlockPage
    ElementContent: DNADesign\Elemental\Models\ElementContent
    Image: SilverStripe\Assets\Image

# content-api-only refs (or overrides) go on the module's registry:
Dynamic\ContentApi\Registry\ClassRegistry:
  models:
    ElementalArea: DNADesign\Elemental\Models\ElementalArea

DNADesign\Elemental\Models\ElementContent:
  api_access: 'GET,POST,PUT'            # colymba HTTP verbs; the module maps them to
                                        # read/create/update (plus: delete, action)
  api_writable_fields: [Title, HTML, Sort, ShowTitle]
  extensions:
    - Dynamic\ContentApi\Write\WriteGuardExtension
```

> **SECURITY:** never grant write verbs (`POST,PUT`) in `api_access` without
> `WriteGuardExtension` (or an explicit trusted-caller decision) — colymba's write path
> natively applies every key in the payload, and a GET-then-PUT-verbatim round trip
> would detach `_many` relations. The guard enforces `api_writable_fields` /
> `api_protected_fields` / `api_writable_relations` — the same keys the module's own
> write pipeline respects — scoped strictly to colymba-controller writes.

2. **Apply the external-id extension** to classes the API should upsert (same column
   spec as `recipe-silverstripe-essentials-fixtures` — legacy-populated sites are
   addressable as-is):

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
DNADesign\Elemental\Models\BaseElement:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
SilverStripe\Assets\File:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
```

3. **Grant permissions** — *Access the content API* (`CONTENT_API_ACCESS`) and, for
   population endpoints, *Use content population endpoints* (`CONTENT_API_POPULATE`) —
   then mint a token:

```bash
sake tasks:MintContentApiToken --email=agent@example.com
```

4. **Call it** — `X-Silverstripe-Apitoken` header on every request, both surfaces:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/content-api/v1/schema/site
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/api/ElementContent
```

## Authentication (colymba)

- `POST/GET api/auth/login?email=…&pwd=…` → `{token, expire, userID}`; `api/auth/logout`.
  Service accounts: the `MintContentApiToken` task (resetToken + getToken).
- Defaults hardened by this module's config: `authentication_policy: true` (tokens
  required on `/api` including GET), `access_control_policy: ACL_CHECK_CONFIG_AND_MODEL`
  (config verbs AND the model's `can*()`), CORS off, `tokenLife` 7 days with activity
  refresh.
- Behavior notes (upstream semantics, documented in
  [docs/en/upstream-issues.md](docs/en/upstream-issues.md)):
  tokens are stored **in plaintext** on the Member; the `?token=` query-var fallback is
  always active (avoid tokens in URLs); a token is valid while
  `ApiTokenExpire > now − tokenLife` (idle tokens live up to 2× tokenLife); every
  authenticated request logs the member into the session IdentityStore and, with
  auto-refresh on, writes the Member row.
- `GET content-api/v1/auth/session` introspects the current token: member, held
  permission codes, expiry.

## Endpoints (this module, `/content-api/v1`)

Envelope: `{"data": ..., "meta": {...}, "error": null}`; errors carry stable
machine-readable codes. Auth failures here are `401 UNAUTHENTICATED|TOKEN_EXPIRED`
(colymba's own surface answers `403` with its native shape).

| Endpoint | Purpose |
|---|---|
| `GET auth/session` | Token introspection |
| `GET records/$ClassRef` | List: `Field__Modifier=` filters, `sort`, `limit`/`offset`, `_stage=draft\|live` |
| `GET records/$ClassRef/$ID` | Read one — numeric or `ext:<external-id>`, stage-aware |
| `POST records/$ClassRef/$ID/publish\|unpublish\|archive` | Stage actions (`{"recursive": true}`) |
| `POST batch` | Ordered `create\|upsert\|update\|delete` ops, per-op results + summary; `atomic` rollback |
| `POST compositions/page` | One atomic request = one page's full composition |
| `POST assets`, `GET assets/$ID` | Asset ingestion (multipart/base64, conflict modes, hash-skip) + read |
| `POST pages/$ID/convert`, `POST pages/$ID/apply-template` | Page class change; elemental-templates apply |
| `GET schema` / `schema/site` / `schema/$ClassRef` | Exposed classes, integrations + live palette, per-class payload contracts, `crud` pointer to the colymba surface |

> `_stage` is underscored because bare `stage` is SilverStripe's own reserved staging
> param. Generic CRUD (`/api`) is stage-unaware: writes land on the current stage
> unpublished — use stage actions, batch `publish`, or compositions for versioned flows.

### Write payload shape (batch ops / compositions)

```json
{
  "fields": { "Title": "Welcome", "BackgroundColor": "$palette(0)",
              "Image": { "externalId": "home-hero-img" },
              "CtaLink": { "type": "ExternalLink", "url": "/contact", "title": "Contact us" } },
  "relations": { "Staff": { "mode": "add", "items": [ { "externalId": "staff-jane",
                 "extraFields": { "SortOrder": 1 } } ] } },
  "externalId": "home-hero",
  "publish": "none|single|recursive"
}
```

PascalCase field names round-trip between GET responses and write payloads on both
surfaces (colymba's serializer also emits verbatim names). has_one values: int, `null`,
`{"id"}`, `{"externalId"}`, a link payload, or `{"$ref"}` inside compositions. Writes
validate before applying — a rejected payload changes nothing.

### Page composition

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

Atomic (rolls back on any failure; asset binaries excepted — content-addressed, converge
on retry). Elements upsert by required `externalId`; array order sets `Sort`; updates are
sparse; `$ref` aliases are per-request; `prune: managed` archives only
externally-identified elements missing from the payload (hand-authored content is
invisible to prune); `publish: recursive` publishes the page plus each written
area/element/child individually. HomePage-style types: `"areaRelation": "ElementalHomePage"`.

## Error codes

`UNAUTHENTICATED` `TOKEN_EXPIRED` `FORBIDDEN` `FORBIDDEN_CLASS` `FORBIDDEN_RECORD`
`ENV_FORBIDDEN` `UNKNOWN_CLASS` `NOT_FOUND` `MULTIPLE_MATCHES` `ALREADY_EXISTS`
`VALIDATION_FAILED` `UNKNOWN_FIELD` `READONLY_FIELD` `UNKNOWN_RELATION` `UNRESOLVED_REF`
`CIRCULAR_REF` `PAYLOAD_INVALID` `URLSEGMENT_COLLISION` `HOMEPAGE_CONVERSION_FORBIDDEN`
`ASSET_CONFLICT` `ASSET_READ_FAILED` `TOKEN_RESOLUTION_FAILED` `EXTERNAL_ID_UNSUPPORTED`
`FEATURE_UNAVAILABLE` `METHOD_NOT_ALLOWED` `SERVER_ERROR`

Guarantees: URLSegment dedup bumps surface as `URLSEGMENT_COLLISION` warnings; an
unresolvable color token fails the write rather than persisting a white-on-white literal.

## Security model

- **Population gating**: batch/compositions/asset writes/page actions require
  `CONTENT_API_POPULATE` and an allowed environment
  (`EnvironmentGate.population_enabled_environments`, default `[dev, test]`;
  `SS_CONTENT_API_ALLOW_POPULATE=1` overrides deliberately).
- **Class gate**: merged models map + per-class `api_access`, deny-by-default; checks run
  against the record's concrete class (subclasses may narrow inherited access);
  `content_api_access` overrides `api_access` for this module's surface only.
- **Record gate**: the model's own `can*()` always applies; `canCreate()` receives a
  context hydrated from payload has_one keys.
- **Write policies**: `guarded` (protected-field denylist) or `allowlist`
  (`api_write_policy: allowlist` + `api_writable_fields`) — enforced natively in this
  module's pipeline and, via `WriteGuardExtension`, on colymba's write path.

## Testing

```bash
# host project composer.json once:
#   "autoload-dev": { "psr-4": { "Dynamic\\ContentApi\\Tests\\": "vendor/dynamic/silverstripe-content-api/tests/" } }
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/dynamic/silverstripe-content-api/tests
```

`SS_PHPUNIT_FLUSH=1` matters (TestOnly stub config needs a flushed test manifest).
The suite includes cross-surface tests that hit colymba's real `/api` route.

## MCP-readiness

[`schema/endpoints.json`](schema/endpoints.json) describes this module's endpoints as
MCP-style tool definitions plus a `genericCrud` pointer at the colymba surface;
`GET /schema/$ClassRef` supplies per-class payload contracts at runtime.

## Supporting the upstream module

Gaps we've bridged locally are proposed upstream so every consumer benefits — see
[docs/en/upstream-issues.md](docs/en/upstream-issues.md) (hashed token storage, native
write allowlist, parameterized token queries, DELETE 204).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
