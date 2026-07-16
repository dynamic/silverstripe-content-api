# Schema introspection

`GET schema` / `GET schema/site` (equivalent) and `GET schema/$ClassRef` let a caller — human
or agent — discover what the API exposes and how to write to it, instead of hardcoding the
class/field inventory. This is what the companion MCP server calls before building any write
payload; see the MCP server's `docs/tools.md` and `docs/workflows.md`.

## `GET schema/site`

Call this first. Returns:

```json
{
  "api": "silverstripe-content-api/v1",
  "environment": "dev",
  "populationEnabled": true,
  "crud": { "provider": "colymba/silverstripe-restfulapi", "route": "api",
            "auth": "api/auth/login", "models": ["BlockPage", "ElementContent", ...] },
  "integrations": { "restfulapi": true, "elemental": true, "linkfield": true,
                     "elementalTemplates": false,
                     "essentialsColors": { "backgroundColors": ["#fff", ...],
                                            "buttonLabels": ["Primary", ...],
                                            "tokens": ["$palette(N)", "$button(N, Label)"] } },
  "classes": { "ElementContent": { "className": "DNADesign\\Elemental\\Models\\ElementContent",
                                    "access": ["read", "create", "update"],
                                    "versioned": true, "externalId": true,
                                    "element": true, "page": false } }
}
```

| Field | Meaning |
|---|---|
| `environment` | Current `Director::get_environment_type()` |
| `populationEnabled` | Whether population endpoints would currently pass [environment gating](04_security-model.md#environment-gating) for this caller |
| `crud` | Where colymba's generic surface lives (route, auth endpoint, exposed models) — a live pointer, not a hardcoded `/api` assumption |
| `integrations` | Which optional integrations are installed. `essentialsColors` is `false` when `essentials-tools` isn't installed, otherwise the live palette/button labels/token syntax |
| `classes` | Every exposed class (deny-by-default — a mapped-but-inaccessible class is omitted entirely, not listed with empty access), keyed by short ref |

## `GET schema/$ClassRef`

One class's full payload contract:

```json
{
  "classRef": "ElementContent", "className": "DNADesign\\Elemental\\Models\\ElementContent",
  "access": ["read", "create", "update"], "versioned": true, "externalIdField": "FixtureIdentifier",
  "fields": { "Title": { "type": "Varchar(255)", "writable": true },
              "BackgroundColor": { "type": "Varchar(7)", "writable": true, "tokens": "palette" } },
  "hasOne": { "Image": { "class": "SilverStripe\\Assets\\Image", "payload": "assetRef", "writable": true } },
  "hasMany": { "Panels": { "class": "App\\Model\\Panel", "writable": true } },
  "manyMany": { "Staff": { "class": "App\\Model\\StaffMember", "writable": false } }
}
```

- `fields`: every db field except `ID`, the external-id column, has_one FK columns (they surface
  under `hasOne` instead), and a polymorphic has_one's companion `{Name}Class` column (managed as
  part of the relation, never independently). `writable` reflects the live
  guarded/allowlist decision (see [Security model](04_security-model.md)). `values` appears for
  `DBEnum` fields. `tokens` appears when the field is in `SchemaService.field_tokens`.
- `hasOne`: `payload` is one of `assetRef` (File subclass target), `link` (linkfield `Link`
  subclass target), or `recordRef` (anything else). For a **polymorphic** has_one, `writable`
  reflects the FK-and-Class-column pair check (`isPolymorphicRelationWritable()`) — never just
  the FK alone.
- `hasMany`/`manyMany`: `writable` reflects membership in `api_writable_relations`.

`externalIdField` is `null` when the class lacks
[`ExternalIdentifierExtension`](02_configuration.md#externalidentifierextension).

Unknown class ref → `404 UNKNOWN_CLASS`.

## Permission

Requires `CONTENT_API_SCHEMA` (implicitly granted to anyone holding `CONTENT_API_ACCESS`) — see
[Security model](04_security-model.md#permission-codes).
