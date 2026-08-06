# Installation

## Requirements

- SilverStripe `^5.2`, PHP `^8.1`
- `colymba/silverstripe-restfulapi` `^5.0` (resolves to
  [dynamic/silverstripe-restfulapi](https://github.com/dynamic/silverstripe-restfulapi) — see
  below)

Optional integrations are feature-gated at runtime: an endpoint that needs one answers
`501 FEATURE_UNAVAILABLE` when it's absent, rather than fataling.

| Package | Enables |
|---|---|
| `dnadesign/silverstripe-elemental` `^5.2` | `POST compositions/page` and everything element-related |
| `silverstripe/linkfield` `^4` | Structured link payloads (`CtaLink: {type, url, ...}`) via `LinkTransformer` |
| `dynamic/silverstripe-essentials-tools` | `$palette(N)` / `$button(N, Label)` color token resolution via `ColorTokenTransformer` |
| `dynamic/silverstripe-elemental-templates` | `POST pages/$ID/apply-template` |

> This is the `1` branch (SilverStripe 5.2 line, default). Branch `2` targets SilverStripe 6
> and requires `colymba/silverstripe-restfulapi`'s `feature/cms-6-compatibility` branch
> instead — see the README's Branch policy section.

## Install

The colymba dependency is consumed from `dynamic/silverstripe-restfulapi`'s tagged `5.0.0` release
(same package name as upstream, so it satisfies the `colymba/silverstripe-restfulapi` constraint
everywhere), so your **project root** `composer.json` must add the VCS entry (Composer does not
inherit a dependency's own `repositories` block):

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/dynamic/silverstripe-restfulapi" }
]
```

```bash
composer require colymba/silverstripe-restfulapi:^5.0
composer require dynamic/silverstripe-content-api
```

> `dynamic/silverstripe-restfulapi` is Dynamic's maintained fork of silverstripeltd's `feature/v5`
> branch, fixing 4 calls to methods removed in SilverStripe 4+ (`Member::login()`/`logout()`,
> `DataObject::stat()`). See [Upstream issues](upstream-issues.md) for background. Because `^5.0`
> resolves to a real tag rather than a dev branch, no `minimum-stability` workaround is needed for
> this dependency specifically.

Then run `dev/build flush=1` and continue to [Quick start](01_quickstart.md).

**Already on the earlier composer-patch setup?** (`colymba/silverstripe-restfulapi:
dev-feature/v5`, a `repositories` entry pointing at `silverstripeltd/silverstripe-restfulapi`,
`cweagans/composer-patches` in `require` and `config.allow-plugins`): update your root
composer.json to the form above — change the colymba constraint to `^5.0`, repoint the
`repositories` entry at `dynamic/silverstripe-restfulapi`, and remove the `cweagans/composer-patches`
requirement and `allow-plugins` entry (the fork's fix no longer needs a patch layered on top of
it). Then `composer remove cweagans/composer-patches && composer update
colymba/silverstripe-restfulapi` (a plain `composer update` on a package no longer in `require`
just warns and leaves it in the lock), and delete `patches.lock.json` if it was committed.
*(Remove this whole note once no consumer of this module is still on the old setup.)*

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
