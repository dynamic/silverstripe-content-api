# Installation

## Requirements

- SilverStripe `^5.2`, PHP `^8.1`
- `colymba/silverstripe-restfulapi` (silverstripeltd `feature/v5` branch — see below)

Optional integrations are feature-gated at runtime: an endpoint that needs one answers
`501 FEATURE_UNAVAILABLE` when it's absent, rather than fataling.

| Package | Enables |
|---|---|
| `dnadesign/silverstripe-elemental` `^5.2` | `POST compositions/page` and everything element-related |
| `silverstripe/linkfield` `^4` | Structured link payloads (`CtaLink: {type, url, ...}`) via `LinkTransformer` |
| `dynamic/silverstripe-essentials-tools` | `$palette(N)` / `$button(N, Label)` color token resolution via `ColorTokenTransformer` |
| `dynamic/silverstripe-elemental-templates` | `POST pages/$ID/apply-template` |

> This is the `ss5` branch. Branch `1` targets SilverStripe 6 and requires
> `colymba/silverstripe-restfulapi`'s `feature/cms-6-compatibility` branch instead — see the
> README's Branch policy section.

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
composer require colymba/silverstripe-restfulapi:dev-feature/v5
composer require dynamic/silverstripe-content-api
```

This module requires `cweagans/composer-patches` and declares a patch against
`colymba/silverstripe-restfulapi` in its own `extra.patches` — the plugin applies
dependency-declared patches automatically, so no action is needed in your project's
composer.json beyond the two requires above.

> `feature/v5` is where silverstripeltd is working towards SS5 support, but it's unreleased and
> calls 4 methods removed in SilverStripe 4+ (`Member::login()`/`logout()`,
> `DataObject::stat()`) — this module's composer patch fixes those specific calls. See
> [Upstream issues](upstream-issues.md) for the tracking issue and the drop conditions for the
> patch. If the branch is renamed, deleted, or the fix lands upstream, update the constraint
> accordingly. Consumers' `composer.lock` pins the exact commit either way.

Then run `dev/build flush=1` and continue to [Quick start](01_quickstart.md).

## Upgrading from 1.0.x

1.1.0 replaced the module's own auth with colymba's:

- Member columns change: `ContentApiTokenHash`/`ContentApiTokenExpire` are abandoned (orphan
  columns; drop manually if you care) — colymba's `ApiToken`/`ApiTokenExpire` are created on
  `dev/build`. **Hashed 1.0.x tokens cannot be carried over — re-mint every service account**
  (`sake dev/tasks/MintContentApiToken email=…`).
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
