# SilverStripe Content API

Token-authenticated REST content API for SilverStripe 6: generic, config-driven CRUD for
DataObjects plus atomic page-composition endpoints for programmatic content population.
The API-driven successor to Populate-YAML-fixture workflows — one `POST` replaces a fixture
file, an attach task and a `PopulateTask` run, idempotently, with per-operation feedback.

Every endpoint maps 1:1 onto a future MCP tool: discrete operations, JSON payloads,
machine-readable error codes (`schema/endpoints.json` holds the tool definitions).

## Requirements

- SilverStripe ^6, PHP ^8.3

Optional integrations (soft dependencies, feature-gated at runtime; endpoints answer
`501 FEATURE_UNAVAILABLE` when absent):

- `dnadesign/silverstripe-elemental` — page-composition endpoints
- `silverstripe/linkfield` — structured link payloads
- `dynamic/silverstripe-essentials-tools` — `$palette()` / `$button()` color tokens
- `dynamic/silverstripe-elemental-templates` — apply-template endpoint

## Installation

```bash
composer require dynamic/silverstripe-content-api
```

## Quick start

1. **Expose classes** (deny-by-default — nothing is reachable until mapped AND granted):

```yml
Dynamic\ContentApi\Registry\ClassRegistry:
  models:
    BlockPage: Dynamic\Base\Page\BlockPage
    ElementContent: DNADesign\Elemental\Models\ElementContent
    ElementCard: Dynamic\Elements\Card\Elements\ElementCard
    Image: SilverStripe\Assets\Image
    File: SilverStripe\Assets\File

Dynamic\Base\Page\BlockPage:
  api_access: 'read,create,update,action'   # verbs: read,create,update,delete,action

DNADesign\Elemental\Models\ElementContent:
  api_access: true                           # all verbs

SilverStripe\Assets\File:
  api_access: 'read,create,update,delete'    # subclasses may narrow with their own value
```

2. **Apply the external-id extension** to classes the API should upsert (same column spec
   as `recipe-silverstripe-essentials-fixtures`, so previously-populated sites are already
   addressable):

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
DNADesign\Elemental\Models\BaseElement:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
SilverStripe\Assets\File:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
DNADesign\Elemental\Models\ElementalArea:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
```

3. **Grant permissions** to the group backing the agent/service account — *Access the
   content API* (`CONTENT_API_ACCESS`) for CRUD, *Use content population endpoints*
   (`CONTENT_API_POPULATE`) for batch/compositions/assets/page actions — then mint a token:

```bash
sake tasks:MintContentApiToken --email=agent@example.com
```

4. **Call it** — `X-Silverstripe-Apitoken` header on every request:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/content-api/v1/schema/site
```

## Endpoints (v1)

All responses use the envelope `{"data": ..., "meta": {...}, "error": null}`. Errors carry
`{"code", "status", "message", "details"}` with a stable machine-readable `code`.

| Endpoint | Purpose |
|---|---|
| `POST auth/login` / `logout` / `refresh`, `GET auth/session` | Token lifecycle (login = JSON body `{email, password}`; tokens hashed at rest) |
| `GET records/$ClassRef` | List: `Field=…` / `Field__PartialMatch=…` filters, `sort=-Field`, `limit`/`offset`, `_stage=draft\|live` |
| `GET records/$ClassRef/$ID` | Read one — `$ID` is numeric or `ext:<external-id>` |
| `POST records/$ClassRef` | Create; `{"mode": "upsert"}` updates when the externalId matches (`409 ALREADY_EXISTS` otherwise) |
| `PATCH records/$ClassRef/$ID` | **Sparse** update — absent keys are never touched |
| `DELETE records/$ClassRef/$ID?mode=archive\|unpublish\|hard` | Delete (versioned classes: archive/unpublish only) |
| `POST records/$ClassRef/$ID/publish\|unpublish\|archive` | Stage actions (`{"recursive": true}` for publish) |
| `POST batch` | Ordered ops with per-op results + summary; `{"atomic": true}` = transaction w/ rollback |
| `POST compositions/page` | One atomic request = one page's full composition (below) |
| `POST assets` | Upload: multipart `file` part **or** JSON `{"base64": …}`; `conflict: overwrite\|skip\|rename`; hash-identical uploads skip the store write but always return the record |
| `GET assets/$ID` | Asset read with `url` + `hash` + `filename` |
| `POST pages/$ID/convert` | Change page class (`newClassInstance`); home page refused without `"force": true` |
| `POST pages/$ID/apply-template` | Apply an elemental-templates Template (`{"templateId", "publish"}`) |
| `GET schema` / `schema/site` / `schema/$ClassRef` | Introspection: exposed classes, integrations + live palette, per-class payload contracts |

> `_stage` is underscored because bare `stage` is SilverStripe's own reserved staging
> parameter — the Versioned middleware consumes it before any controller runs.

### Write payload shape

```json
{
  "fields": { "Title": "Welcome", "Sort": 2, "BackgroundColor": "$palette(0)",
              "Image": { "externalId": "home-hero-img" },
              "CtaLink": { "type": "ExternalLink", "url": "/contact", "title": "Contact us" } },
  "relations": { "Staff": { "mode": "add", "items": [ { "externalId": "staff-jane",
                 "extraFields": { "SortOrder": 1 } } ] } },
  "externalId": "home-hero",
  "mode": "upsert",
  "publish": "none|single|recursive"
}
```

- Field names are **PascalCase** (native SilverStripe) — GET responses round-trip into
  PATCH payloads.
- has_one values: integer ID, `null` (clears), `{"id": n}`, `{"externalId": "…"}`, a link
  payload (`{"type": …}`), or `{"$ref": "…"}` inside compositions.
- Relation writes require the relation in the class's `api_writable_relations` list.
- Link payloads: `type` = ExternalLink (`url`) | SiteTreeLink (`pageId`, `anchor`) |
  EmailLink (`email`) | PhoneLink (`phone`) | FileLink (`fileId`); plus `title`,
  `openInNew`. Prefer site-relative ExternalLink urls — environment-independent.

### Page composition (the populate replacement)

```json
{
  "page": {
    "match": { "urlSegment": "about" },
    "createIfMissing": { "title": "About", "parentId": 0, "className": "BlockPage" },
    "convertTo": "BlockPage",
    "areaRelation": "ElementalArea",
    "fields": { "MetaDescription": "…" }
  },
  "publish": "recursive",
  "prune": { "enabled": true, "scope": "managed" },
  "assets": [
    { "ref": "heroImg", "externalId": "about-hero", "folder": "about",
      "filename": "hero.jpg", "base64": "…", "conflict": "overwrite" }
  ],
  "elements": [
    { "class": "ElementRow", "externalId": "about-row-1", "fields": {} },
    { "class": "ElementCard", "externalId": "about-card-1",
      "fields": { "Title": "Who We Are", "Image": { "$ref": "heroImg" },
                  "ElementLink": { "type": "ExternalLink", "url": "/contact", "title": "Talk to us" } },
      "children": { "Panels": [ { "externalId": "about-card-1-p1", "fields": { "Title": "…" } } ] } }
  ]
}
```

Semantics:

- **Atomic** — any failure rolls the whole composition back (asset binaries excepted:
  they're content-addressed and converge on retry).
- Elements **upsert by externalId** (required per element); array order sets `Sort`
  unless `fields.Sort` is explicit; updates are sparse.
- `$ref` aliases are **per-request** (assets resolve first, element→element refs get a
  deferred retry pass). Follow-up sparse payloads should omit resolved fields or use
  `{"externalId": …}` instead.
- `prune.scope: managed` archives only externalId-bearing elements missing from the
  payload — hand-authored CMS content is invisible to prune. `all` is the explicit
  dangerous variant for fully machine-owned pages.
- `publish: recursive` publishes the page **plus each written area/element/child
  individually** (a page `publishRecursive()` does not cascade into elements).
- HomePage-style page types: pass `"areaRelation": "ElementalHomePage"`.

### Batch

Per-op results are the agent self-correction contract — transport is HTTP 200 even when
ops fail; each result carries `status: created|updated|deleted|error` (+ `error.code`).
`{"atomic": true}` turns the first failure into a 422 with `rolledBack: true`.

## Security model

- **Auth**: per-Member token (sha256 hash at rest, `ContentApiTokenHash`), 7-day life +
  activity refresh by default, no query-var tokens, no session/IdentityStore involvement.
- **Permissions**: `CONTENT_API_ACCESS` (everything), `CONTENT_API_POPULATE`
  (batch/compositions/asset writes/page actions), `CONTENT_API_SCHEMA` (introspection,
  implied by ACCESS).
- **Class gate**: registry `models` map + per-class `api_access` verbs, deny-by-default;
  `content_api_access` wins over `api_access` when colymba/restfulapi coexists. Checks run
  against the record's **concrete class** (subclasses may narrow inherited access).
- **Record gate**: the model's own `canView/canEdit/canCreate/canDelete` always apply;
  `canCreate()` receives a context hydrated from the payload's has_one keys.
- **Write policies**: `guarded` (default — everything except the protected denylist +
  per-class `api_protected_fields`) or `allowlist` (`api_write_policy: allowlist` +
  `api_writable_fields`). Validation happens before anything is applied.
- **Environment gate**: population endpoints run in `dev`/`test` only by default
  (`Dynamic\ContentApi\Security\EnvironmentGate.population_enabled_environments`);
  override deliberately with `SS_CONTENT_API_ALLOW_POPULATE=1`.

## Error codes

`UNAUTHENTICATED` `TOKEN_EXPIRED` `FORBIDDEN` `FORBIDDEN_CLASS` `FORBIDDEN_RECORD`
`ENV_FORBIDDEN` `UNKNOWN_CLASS` `NOT_FOUND` `MULTIPLE_MATCHES` `ALREADY_EXISTS`
`VALIDATION_FAILED` `UNKNOWN_FIELD` `READONLY_FIELD` `UNKNOWN_RELATION` `UNRESOLVED_REF`
`CIRCULAR_REF` `PAYLOAD_INVALID` `URLSEGMENT_COLLISION` `HOMEPAGE_CONVERSION_FORBIDDEN`
`ASSET_CONFLICT` `ASSET_READ_FAILED` `TOKEN_RESOLUTION_FAILED` `EXTERNAL_ID_UNSUPPORTED`
`FEATURE_UNAVAILABLE` `METHOD_NOT_ALLOWED` `SERVER_ERROR`

Notable guarantees: a URLSegment dedup bump is surfaced as a `URLSEGMENT_COLLISION`
warning (never hidden behind a green response); an unresolvable color token **fails the
write** rather than persisting a white-on-white literal.

## Migrating from Populate fixtures

See [docs/en/migrating-from-fixtures.md](docs/en/migrating-from-fixtures.md). Short
version: `FixtureIdentifier` values carry over unchanged (`PopulateMergeMatch:
[FixtureIdentifier]` ≙ upsert-by-externalId), fixture YAML blocks become composition
`elements`, `PopulateFileFrom` becomes `POST assets`, the attach task disappears
(compositions match pages directly), and `$palette()`/`$button()` tokens work as-is.

## Testing

Module suite (inside a host project, e.g. the essentials-ss6 testbed):

```bash
# map the test namespace once in the host project's composer.json:
#   "autoload-dev": { "psr-4": { "Dynamic\\ContentApi\\Tests\\": "vendor/dynamic/silverstripe-content-api/tests/" } }
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/dynamic/silverstripe-content-api/tests
```

`SS_PHPUNIT_FLUSH=1` matters: the TestOnly stubs' private-static config needs a flushed
test manifest. Optional-integration suites (elemental, linkfield, essentials colors,
templates) skip themselves when the dependency is absent.

## MCP-readiness

[`schema/endpoints.json`](schema/endpoints.json) describes every endpoint as an MCP-style
tool definition (name, description, JSON input schema). A future MCP server is a thin
transport wrapper: one tool per entry, calling the REST endpoint with the member token.
`GET /schema/$ClassRef` supplies the per-class payload contracts at runtime.

## Attribution

The token-authentication flow and config-driven `api_access` model are adapted from
[colymba/silverstripe-restfulapi](https://github.com/colymba/silverstripe-restfulapi)
(BSD-3-Clause, © Thierry Francois @colymba), with hardening: tokens hashed at rest,
POST-body login, no query-var tokens by default, no session involvement, native write
allowlists, Versioned awareness.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
