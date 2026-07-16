# Testing & contributing

## Host project setup

This module is developed as a vendor checkout inside a host SilverStripe project (not
standalone) — its own `require-dev`/`autoload-dev` are never installed by Composer when it's a
dependency. The host project needs, once:

```json
"autoload-dev": {
    "psr-4": { "Dynamic\\ContentApi\\Tests\\": "vendor/dynamic/silverstripe-content-api/tests/" }
}
```

plus `squizlabs/php_codesniffer` and `phpstan/phpstan` as **project-level** `require-dev`
(installing them globally doesn't give the project's own `vendor/bin/phpcs` access to this
module's `phpcs.xml.dist` ruleset).

## Running the suite

```bash
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/dynamic/silverstripe-content-api/tests
```

`SS_PHPUNIT_FLUSH=1` matters — PHPUnit's class manifest (`classmanifest_tests`) is a separate
cache bucket from the one `dev/build flush=1` clears, and goes stale independently whenever a
`TestOnly` stub class is added or changed. A `references nonexistent X in 'extensions'` failure
for a stub class that loads fine elsewhere is almost always this, not a real autoload gap — run
once with the flush and subsequent runs pass without it.

The suite includes cross-surface tests that hit colymba's real `/api` route, so a full run needs
the host project's DB wired up (SilverStripe's `SapphireTest` per-run `ss_tmpdb_*` databases —
DDEV's default `db` user needs `GRANT ALL PRIVILEGES ON `ss_tmpdb_%`.* TO 'db'@'%'` before
PHPUnit can create them at all).

```bash
vendor/bin/phpcs src/ tests/     # or: composer lint (from the module directory)
vendor/bin/phpcbf src/ tests/    # auto-fix — composer lint-clean
vendor/bin/phpstan analyse       # phpstan.neon.dist
```

## Test suite map

| File | Covers |
|---|---|
| `Control/AssetsTest.php` | Asset upload/read: create, idempotent identical content, conflict modes, publish flag, permission + env gating, payload validation |
| `Control/AuthTest.php` | Auth adapter error mapping, session introspection, cross-surface contract with colymba's own auth |
| `Control/BatchTest.php` | Batch ops through the RecordWriter/WriteApplicator path |
| `Control/ColorTokenTest.php` | `$palette`/`$button` resolution (only runs where essentials-tools is installed) |
| `Control/CompositionTest.php` | Full-page composition payload behavior |
| `Control/EnvironmentGateTest.php` | Environment gating, including the `SS_CONTENT_API_ALLOW_POPULATE` blocking-value data provider |
| `Control/PageActionsTest.php` | Page convert: no-op same-class, homepage refusal, permission/env gating; also covers the `URLSEGMENT_COLLISION` warning surfaced on a URLSegment-setting write |
| `Control/RecordActionsTest.php` | Stage actions, unknown action 404, verb requirements |
| `Control/RecordSerializerTest.php` | Unreadable-relation dedup logging, polymorphic relation edge cases |
| `Control/RecordsReadTest.php` | List/read filters, sort/pagination, id + `ext:` reads, `_stage`, permission enforcement |
| `Control/SchemaTest.php` | Site/class schema shape, enum values, allowlist reflection, polymorphic writability |
| `Control/WriteGuardTest.php` | Colymba `/api` write path via `WriteGuardExtension` |
| `Control/WriteGuardPolymorphicTest.php` | Polymorphic `{"class","id"}` payload + `{Name}Class` translation on the colymba surface |
| `Control/WriteGuardEncodeFailureTest.php` | `json_encode` failure path in the guard's re-encode step |
| `Errors/ApiErrorTest.php` | `fromValidation()` maps structured messages, never a raw exception string |
| `Security/PermissionPolicyTest.php` | `buildCreateContext()` has_one hydration, incl. trusted-field-only relations |
| `Write/WriteApplicatorTest.php` | Trusted channel setting a polymorphic Class column directly |
| `ContentApiTestCase.php` + `Stub/*.php` | Shared fixture/registry/token plumbing and test DataObjects |

## Gotchas

> **A stray `app/` doesn't belong in this checkout.** `.gitignore` excludes `/app/` as
> "recipe-plugin scaffolding; not module code." If an `app/code/Page.php`/`PageController.php`
> scaffold ever lands on disk here (e.g. left over from another tool run), delete it — a vendor
> module has no business defining a `Page` class. A full class-manifest rebuild (`dev/build`, an
> unscoped `phpunit`/`phpstan` run, or the flush above) fatals with `There are two files
> containing the "Page" class` for as long as it sits there. Module-scoped `phpcs`/`phpstan
> analyse <file>` don't boot the kernel, so this is easy to miss on a narrow check and only
> surfaces on a full run.

- The DDEV config manifest cache lives at `/tmp/silverstripe-cache-<hash>` **inside the web
  container**, not a project-relative path — clear it after editing any `_config/*.yml` before a
  CLI `phpunit`/`sake` run picks up the change.

## Keeping `schema/endpoints.json` in sync with the MCP server

[`schema/endpoints.json`](../../schema/endpoints.json) is the **source of truth** for the
companion MCP server's tool definitions (`dynamic/silverstripe-content-api-mcp`) — every
endpoint's name, description, method, path, and input schema is authored here, then copied
verbatim into the MCP repo. When you add or change an endpoint:

1. Update `schema/endpoints.json` (and bump its `version` field).
2. In the MCP repo, run `scripts/sync-spec.sh <path-to-this-checkout>` — **the script's default
   path is stale** (`~/Sites/silverstripe-content-api`, a checkout that no longer exists), so
   pass this module's path explicitly, e.g.:
   ```bash
   scripts/sync-spec.sh ~/Sites/content-api-testbed/vendor/dynamic/silverstripe-content-api
   ```
3. Review the diff, bump the MCP repo's `pyproject.toml` version, PR, and tag a release so
   consumers pick up the change.

Check the MCP repo's bundled spec version against this module's before assuming they're in sync
— see that repo's `docs/development.md`.
