# Installation

## Requirements

- SilverStripe `^6`, PHP `^8.3`
- `colymba/silverstripe-restfulapi` (silverstripeltd `feature/cms-6-compatibility` branch — see below)

Optional integrations are feature-gated at runtime: an endpoint that needs one answers
`501 FEATURE_UNAVAILABLE` when it's absent, rather than fataling.

| Package | Enables |
|---|---|
| `dnadesign/silverstripe-elemental` `^6` | `POST compositions/page` and everything element-related |
| `silverstripe/linkfield` `^5` | Structured link payloads (`CtaLink: {type, url, ...}`) via `LinkTransformer` |
| `dynamic/silverstripe-essentials-tools` | `$palette(N)` / `$button(N, Label)` color token resolution via `ColorTokenTransformer` |
| `dynamic/silverstripe-elemental-templates` | `POST pages/$ID/apply-template` |

## Install

The colymba dependency is consumed from a **dev branch** with no Packagist release, so your
**project root** `composer.json` must both add the VCS entry (Composer does not inherit a
dependency's own `repositories` block) **and** require the branch at root — a `dev-` constraint's
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
> support. If it is renamed or deleted after an upstream release, update the constraint to the
> tagged version. Consumers' `composer.lock` pins the exact commit either way.

Then run `dev/build flush=1` and continue to [Quick start](01_quickstart.md).

## Upgrading from 1.0.x

1.1.0 replaced the module's own auth with colymba's:

- Member columns change: `ContentApiTokenHash`/`ContentApiTokenExpire` are abandoned (orphan
  columns; drop manually if you care) — colymba's `ApiToken`/`ApiTokenExpire` are created on
  `dev/build`. **Hashed 1.0.x tokens cannot be carried over — re-mint every service account**
  (`sake tasks:MintContentApiToken --email=…`).
- Removed endpoints → replacements: `POST records/$Class` → `POST api/$Class` or a batch
  `create`/`upsert` op; `PATCH records/$Class/$ID` → `PUT api/$Class/$ID` or a batch `update`
  op; `DELETE records/$Class/$ID` → `DELETE api/$Class/$ID` (hard delete!) or a batch `delete`
  op / archive stage action. `POST auth/login|logout|refresh` → `api/auth/login|logout`
  (email/pwd request vars); there is no refresh endpoint — set a longer `tokenLife` and re-mint
  (auto-refresh is off, see [Authentication](03_authentication.md)).
- Injector note: `RecordsWriteHandler` was replaced by `RecordActionsHandler`.

## Upgrading from 1.1.x

1.2.0 is a security/correctness release with no route, envelope, or config changes — see
[CHANGELOG.md](../../CHANGELOG.md) for the full list. A few fixes are observable response
differences if client code happened to depend on the old (buggy) shape:

- `GET records/$ClassRef` list reads: `meta.total` now reflects only records the caller can
  view (previously it counted hidden records too), and a page is never short a record that was
  silently filtered out after pagination.
- A polymorphic has_one pointing at a class not registered in `ClassRegistry` now serializes as
  `{"id": n}` with no `"class"` key, instead of `{"id": n, "class": null}`.
- Four validation-error responses (composition page/child writes, page conversion, link writes)
  now return the same structured `details` array every other `VALIDATION_FAILED` response
  already used, rather than a raw exception message — only relevant if client code was parsing
  that specific message text.

1.3.0 added the `filePath` alternative to `base64` on asset uploads (MCP-client-resolved, see
[Assets](09_assets.md)) and fixed `CompositionService::publishAll()` crashing on compositions
whose has_many children include a non-Versioned class — no action required to adopt.
