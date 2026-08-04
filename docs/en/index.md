# silverstripe-content-api documentation

Content population layer for SilverStripe 5.2+, built on
[colymba/silverstripe-restfulapi](https://github.com/colymba/silverstripe-restfulapi) (the
silverstripeltd-maintained `feature/v5` line — see [Installation](00_installation.md)). This is
the `ss5` branch; branch `1` targets SilverStripe 6.

Two cooperating surfaces share one token and one models map:

| Surface | Route | Provides |
|---|---|---|
| colymba RESTfulAPI | `/api` | `GET/POST/PUT/DELETE api/$Model(/$ID)`, `api/auth/login\|logout`, token auth |
| This module | `/content-api/v1` | Stage-aware GET (`_stage`, `ext:`), publish/unpublish/archive actions, batch, compositions, assets, pages, schema, `GET auth/session` |

## Start here

- **New to the module?** [Installation](00_installation.md) → [Quick start](01_quickstart.md)
- **Configuring a project?** [Configuration reference](02_configuration.md) · [Authentication](03_authentication.md) · [Security model](04_security-model.md)
- **Calling the API?** [Endpoint reference](05_endpoint-reference.md) · [Write payloads](06_write-payloads.md) · [Batch operations](07_batch-operations.md) · [Page compositions](08_page-compositions.md) · [Assets](09_assets.md) · [Publishing & stages](10_publishing-and-stages.md) · [Schema introspection](11_schema-introspection.md) · [Error codes](12_error-codes.md)
- **Migrating from Populate fixtures?** [Migrating from fixtures](13_migrating-from-fixtures.md)
- **Contributing to the module?** [Architecture](14_architecture.md) · [Testing & contributing](15_testing-and-contributing.md) · [Upstream issues workstream](upstream-issues.md)

## Page index

| # | Page | What it covers |
|---|---|---|
| 00 | [Installation](00_installation.md) | Requirements, the colymba dev-branch install, optional integrations, upgrade notes |
| 01 | [Quick start](01_quickstart.md) | Expose a class, mint a token, first calls on both surfaces |
| 02 | [Configuration reference](02_configuration.md) | Every config option across every class, with defaults |
| 03 | [Authentication](03_authentication.md) | Token model, minting, the hardened `/content-api/v1` auth check, colymba `/api` caveats |
| 04 | [Security model](04_security-model.md) | Permission codes, class/record ACL, write policies, the trusted internal-fields channel |
| 05 | [Endpoint reference](05_endpoint-reference.md) | Every `/content-api/v1` route, method, params |
| 06 | [Write payloads](06_write-payloads.md) | `fields`/`relations`/`externalId`/`publish` shape, has_one kinds, polymorphic relations |
| 07 | [Batch operations](07_batch-operations.md) | Ordered ops, atomic rollback, delete modes |
| 08 | [Page compositions](08_page-compositions.md) | Atomic full-page writes: elements, children, assets, prune, publish |
| 09 | [Assets](09_assets.md) | Upload/read, conflict modes, hash-skip |
| 10 | [Publishing & stages](10_publishing-and-stages.md) | Draft/live, `_stage`, publish modes |
| 11 | [Schema introspection](11_schema-introspection.md) | `GET schema` / `schema/site` / `schema/$ClassRef` |
| 12 | [Error codes](12_error-codes.md) | The full machine-readable error code reference |
| 13 | [Migrating from fixtures](13_migrating-from-fixtures.md) | Populate-YAML → content API concept map |
| 14 | [Architecture](14_architecture.md) | Request lifecycle, service map, the two write surfaces |
| 15 | [Testing & contributing](15_testing-and-contributing.md) | Test setup, running the suite, the spec-sync workflow |
| — | [Upstream issues](upstream-issues.md) | The colymba/silverstripe-restfulapi support workstream |

See also: the companion [MCP server](https://github.com/dynamic/silverstripe-content-api-mcp), which exposes this
module's endpoints as MCP tools for agent use.
