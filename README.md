# SilverStripe Content API

Token-authenticated REST content API for SilverStripe 6: generic, config-driven CRUD for
DataObjects plus batch page-composition endpoints for programmatic content population
(the API-driven successor to Populate YAML fixture workflows).

Every endpoint is designed to map 1:1 onto a future MCP tool: discrete operations,
JSON payloads, machine-readable error codes, per-operation results.

## Requirements

- SilverStripe ^6
- PHP ^8.3

Optional integrations (soft dependencies, feature-gated at runtime):

- `dnadesign/silverstripe-elemental` — page-composition endpoints
- `silverstripe/linkfield` — structured link payloads
- `dynamic/silverstripe-essentials-tools` — `$palette()` / `$button()` color token resolution
- `dynamic/silverstripe-elemental-templates` — apply-template endpoint

## Installation

```bash
composer require dynamic/silverstripe-content-api
```

## Quick start

1. Expose classes in YAML (deny-by-default):

```yml
Dynamic\ContentApi\Registry\ClassRegistry:
  models:
    BlockPage: Dynamic\Base\Page\BlockPage
    ElementCard: Dynamic\Elements\Card\Elements\ElementCard

Dynamic\Base\Page\BlockPage:
  api_access: 'read,update,action'

Dynamic\Elements\Card\Elements\ElementCard:
  api_access: true
```

2. Grant the **Content API access** permission to the group backing your agent/service
   account, then mint a token:

```bash
sake tasks:MintContentApiToken --email=agent@example.com
```

3. Call the API with the `X-Silverstripe-Apitoken` header:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" \
  https://example.test/content-api/v1/records/ElementCard/ext:home-hero
```

## Endpoints (v1)

| Endpoint | Purpose |
|---|---|
| `POST auth/login` `logout` `refresh`, `GET auth/session` | Token lifecycle |
| `GET records/$ClassRef[/$ID\|/ext:$ExternalId]` | Read one / list with `Field__Modifier=` filters, `sort`, `limit`/`offset`, `_stage=draft\|live` |

Write CRUD, batch, page compositions, assets, page actions and schema introspection land
in later milestones — see the plan in the repo issues.

## Idempotency

Records are addressed by an external identifier stored in the `FixtureIdentifier` column
(same spec as `recipe-silverstripe-essentials-fixtures`, so previously-populated sites are
immediately addressable). Apply the extension to the classes the API should manage:

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Dynamic\ContentApi\Identity\ExternalIdentifierExtension
```

## Attribution

The token-authentication flow and config-driven `api_access` model are adapted from
[colymba/silverstripe-restfulapi](https://github.com/colymba/silverstripe-restfulapi)
(BSD-3-Clause, © Thierry Francois @colymba), with hardening: tokens hashed at rest,
POST-body login, no query-var tokens by default, no session involvement.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
