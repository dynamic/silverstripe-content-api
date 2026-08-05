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

Every endpoint maps 1:1 onto an MCP tool (`schema/endpoints.json` holds the tool
definitions — see the companion [`silverstripe-content-api-mcp`](https://github.com/dynamic/silverstripe-content-api-mcp)
server). The module is the API-driven successor to Populate-YAML-fixture workflows —
see [docs/en/13_migrating-from-fixtures.md](docs/en/13_migrating-from-fixtures.md).

## Requirements

- SilverStripe ^6, PHP ^8.3
- `colymba/silverstripe-restfulapi` (silverstripeltd `feature/cms-6-compatibility` branch)

Optional integrations (feature-gated at runtime; endpoints answer `501
FEATURE_UNAVAILABLE` when absent): `dnadesign/silverstripe-elemental` (compositions),
`silverstripe/linkfield` (link payloads), `dynamic/silverstripe-essentials-tools`
(`$palette()`/`$button()` color tokens), `dynamic/silverstripe-elemental-templates`
(apply-template).

## Installation

The colymba dependency is consumed from a **dev branch** with no Packagist release, so
your **project root** composer.json must both add the VCS entry (composer ignores a
dependency's own `repositories`) **and** require the branch at root — a `dev-` constraint's
stability flag only applies when declared by the root package, so a default
`minimum-stability: stable` host cannot resolve it transitively:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/silverstripeltd/silverstripe-restfulapi" }
]
```

```bash
# root require carries the dev-branch stability flag; the inline alias lets a
# stable host satisfy other packages' "^…" constraints against it:
composer require colymba/silverstripe-restfulapi:"dev-feature/cms-6-compatibility as 3.0.0"
composer require dynamic/silverstripe-content-api
```

> The pinned branch (`feature/cms-6-compatibility`) is where silverstripeltd maintains SS6
> support. If it is renamed or deleted after an upstream release, update the constraint to
> the tagged version. Consumers' `composer.lock` pins the exact commit either way.

Upgrading from an earlier version? See
[docs/en/00_installation.md](docs/en/00_installation.md#upgrading-from-10x) for the 1.0.x
and 1.1.x migration notes.

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

2. **Apply extensions** — the external-id extension to classes the API should upsert, and
   `ContentApiGrantExtension` to any class a service account needs to write without holding
   `ADMIN`:

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Dynamic\ContentApi\Identity\ExternalIdentifierExtension
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
DNADesign\Elemental\Models\BaseElement:
  extensions:
    - Dynamic\ContentApi\Identity\ExternalIdentifierExtension
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
SilverStripe\Assets\File:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
```

3. **Grant permissions and mint a token**:

```bash
sake tasks:MintContentApiToken --email=agent@example.com
```

4. **Call it**:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/content-api/v1/schema/site
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/api/ElementContent
```

Full walkthrough with permission codes and next steps: [docs/en/01_quickstart.md](docs/en/01_quickstart.md).

## Security

> **SECURITY:** never grant write verbs (`POST,PUT`) in `api_access` without
> `WriteGuardExtension` — colymba's write path natively applies every key in the payload
> with no writability check at all. See [docs/en/04_security-model.md](docs/en/04_security-model.md)
> for the full class/record/field ACL model, write policies, and why the two write
> surfaces fail differently (revert-and-200 vs. reject-the-payload).

## Documentation

Full reference lives in [docs/en/](docs/en/index.md):

| Page | Covers |
|---|---|
| [Installation](docs/en/00_installation.md) | Requirements, install, upgrade notes |
| [Quick start](docs/en/01_quickstart.md) | Expose a class, mint a token, first calls |
| [Configuration reference](docs/en/02_configuration.md) | Every config option, every class, with defaults |
| [Authentication](docs/en/03_authentication.md) | Token model, minting, hardened auth, colymba caveats |
| [Security model](docs/en/04_security-model.md) | Permission codes, ACL, write policies, trusted fields channel |
| [Endpoint reference](docs/en/05_endpoint-reference.md) | Every route, method, params |
| [Write payloads](docs/en/06_write-payloads.md) | `fields`/`relations` shape, polymorphic has_one, color tokens, links |
| [Batch operations](docs/en/07_batch-operations.md) | Ordered ops, atomic rollback, delete modes |
| [Page compositions](docs/en/08_page-compositions.md) | Atomic full-page writes |
| [Assets](docs/en/09_assets.md) | Upload/read, conflict modes |
| [Publishing & stages](docs/en/10_publishing-and-stages.md) | Draft/live, `_stage`, publish modes |
| [Schema introspection](docs/en/11_schema-introspection.md) | `GET schema` family |
| [Error codes](docs/en/12_error-codes.md) | The full machine-readable error reference |
| [Migrating from fixtures](docs/en/13_migrating-from-fixtures.md) | Populate-YAML → content API |
| [Architecture](docs/en/14_architecture.md) | Request lifecycle, service map, the two write surfaces |
| [Testing & contributing](docs/en/15_testing-and-contributing.md) | Test setup, running the suite, spec-sync |
| [Upstream issues](docs/en/upstream-issues.md) | The colymba/silverstripe-restfulapi support workstream |

`schema/endpoints.json` describes this module's endpoints as MCP-style tool definitions;
`GET /schema/$ClassRef` supplies per-class payload contracts at runtime.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
