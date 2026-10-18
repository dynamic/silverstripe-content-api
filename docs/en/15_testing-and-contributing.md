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
vendor/bin/phpunit -c vendor/dynamic/silverstripe-content-api/phpunit.xml.dist
```

Point `-c` at the module's config: PHPUnit only looks for a config in the current directory, and
the module's is what loads `tests/bootstrap.php` (which drops the shared config cache, see below).

PHPUnit's class manifest (`classmanifest_tests`) is a separate cache bucket from the one
`dev/build flush=1` clears, and goes stale independently whenever a `TestOnly` stub class is
added or changed. A `references nonexistent X in 'extensions'` failure for a stub class that loads
fine elsewhere is almost always this, not a real autoload gap. On this branch `SS_PHPUNIT_FLUSH=1`
does nothing (SS5's `SapphireTest` reads `flush` only from the request); wipe the cache instead,
`ddev exec 'rm -rf /tmp/silverstripe-cache-*'`, and run again.

The suite includes cross-surface tests that hit colymba's real `/api` route, so a full run needs
the host project's DB wired up (SilverStripe's `SapphireTest` per-run `ss_tmpdb_*` databases —
DDEV's default `db` user needs `GRANT ALL PRIVILEGES ON `ss_tmpdb_%`.* TO 'db'@'%'` before
PHPUnit can create them at all).

```bash
vendor/bin/phpcs src/ tests/     # or: composer lint (from the module directory)
vendor/bin/phpcbf src/ tests/    # auto-fix — composer lint-clean
vendor/bin/phpstan analyse       # phpstan.neon.dist
```

PHPStan, `dev/build` and PHPUnit are all gates: `local-ci --strict` must be green before a PR.
PHPStan needs `cambis/silverstan`, which the `phpstan/extension-installer` Composer plugin loads.
An install with `--no-plugins` leaves it out, and `phpstan-bootstrap.php` then stops the run with
that message rather than reporting SilverStripe config properties and extension members as
errors. Reinstall with plugins enabled. `dev/build` can run before the suite: `tests/bootstrap.php`
drops the config cache the live and test kernels share, so the test kernel rebuilds it with the
TestOnly stubs.

### Standalone (no host project)

The suite also runs from a standalone module checkout with its own `composer install`, using
any reachable MySQL database. Inside a DDEV web container:

```bash
env SS_DATABASE_CLASS=MySQLDatabase SS_DATABASE_SERVER=db SS_DATABASE_USERNAME=db \
    SS_DATABASE_PASSWORD=db SS_DATABASE_NAME=standalone SS_ENVIRONMENT_TYPE=dev \
    vendor/bin/phpunit
```

Extensions a host normally applies through its exposure YAML (`ExternalIdentifierExtension`
on `File`/`BaseElement`, `ContentApiGrantExtension` on `SiteTree`) are applied by
`ContentApiTestCase::getRequiredExtensions()`. That is the minimum the suite needs, not a
host's full exposure config: a test that depends on any other extension must add it there too,
or it passes in a host and fails standalone. When `app/` is missing, silverstripe/cms's test
bootstrap scaffolds `app/code/Page.php` in the checkout. Delete it before the next host-project
run (see Gotchas).

## Pre-push gate

This repo has **no GitHub Actions workflow** — deliberately, not an oversight. `gh api
repos/dynamic/silverstripe-content-api/actions/permissions` returns `{"enabled": false}`,
consistent with the other `dynamic/*` module repos. The actual pre-push gate is `local-ci`,
which auto-detects this repo's `phpunit.xml.dist`/`phpcs.xml.dist`/`phpstan.neon.dist` and
additionally runs the project-declared `scripts/check-doc-drift.sh` (`.local-ci.json`). Run it
(or the commands above by hand) before opening a
PR — nothing else will catch a regression for you.

## Test suite map

| File | Covers |
|---|---|
| `Control/AssetsTest.php` | Asset upload/read: create, idempotent identical content, conflict modes, publish flag, permission + env gating, payload validation |
| `Control/AuthTest.php` | Auth adapter error mapping, session introspection, cross-surface contract with colymba's own auth |
| `Control/BatchTest.php` | Batch ops through the RecordWriter/WriteApplicator path, incl. `dryRun` |
| `Control/ColorTokenTest.php` | `$palette`/`$button` resolution (only runs where essentials-tools is installed) |
| `Control/CompositionTest.php` | Full-page composition payload behavior |
| `Control/EnvironmentGateTest.php` | Environment gating, including the `SS_CONTENT_API_ALLOW_POPULATE` blocking-value data provider |
| `Control/FingerprintTest.php` | `GET fingerprint`: path keying across a reparent, sorted/byte-stable ordering, the Race Team scenario (live child under a draft-only parent), unresolved-owner bucketing, `related` reachability one hop out, `classes`/`includeIds` params, permission-filtered classes in `meta.skipped` |
| `Control/PageActionsTest.php` | Page convert: no-op same-class, homepage refusal, permission/env gating; also covers the `URLSEGMENT_COLLISION` warning surfaced on a URLSegment-setting write |
| `Control/RecordParityTest.php` | `GET .../parity`: field comparison, liveExists/ok semantics, depth-2 owned mismatch, `include`/`depth` params, authorization |
| `Control/RecordActionsTest.php` | Stage actions, unknown action 404, verb requirements |
| `Control/RecordSerializerTest.php` | Unreadable-relation dedup logging, polymorphic relation edge cases |
| `Control/RecordsReadTest.php` | List/read filters, sort/pagination, id + `ext:` reads, `_stage`, permission enforcement |
| `Control/RequestLoggingTest.php` | `RequestLogger` wired through the real controller: off-by-default, real endpoint/status/errorCode captured, a partially-failed `POST batch`'s `opFailures` logged despite the wire response being 200 (#207) |
| `Control/SchemaTest.php` | Site/class schema shape, enum values, allowlist reflection, polymorphic writability |
| `Control/WriteGuardTest.php` | Colymba `/api` write path via `WriteGuardExtension` |
| `Control/WriteGuardPolymorphicTest.php` | Polymorphic `{"class","id"}` payload + `{Name}Class` translation on the colymba surface |
| `Control/WriteGuardEncodeFailureTest.php` | `json_encode` failure path in the guard's re-encode step |
| `Errors/ApiErrorTest.php` | `fromValidation()` maps structured messages, never a raw exception string |
| `Errors/ErrorCodeTest.php` | Every `ErrorCode` case maps to a valid HTTP status — guards the exhaustive `httpStatus()` match against an unmapped new case |
| `Logging/RequestLoggerTest.php` | `RequestLogger` in isolation: off by default, `enabled_environments`, the `SS_CONTENT_API_REQUEST_LOG` force-ON-only override, entry field shape, a throwing project logger never propagating (#207) |
| `Registry/ClassRegistryTest.php` | `accessVerbs()`/`ownAccessVerbs()` inherited-vs-uninherited resolution, discovery (`discovery_roots`/`discovery_write_policy`/`discovery_exclude`), mandatory denylist, manual-model precedence |
| `Security/ContentApiGrantExtensionTest.php` | `ContentApiGrantExtension`: escalation regression (a declared class's verbs must not leak to an undeclared subclass), per-verb scoping, the never-`false` guard, `VIEW_DRAFT_CONTENT`/`Versioned::canDelete()` interactions, `BaseElement::canCreate()` non-delegation |
| `Security/PermissionPolicyTest.php` | `buildCreateContext()` has_one hydration, incl. trusted-field-only relations |
| `Tasks/Support/GrantExtensionReachabilityCheckerTest.php` | `check()`: unreachable `extendedCan()` detection (#103); `checkMissingGrantExtension()`: classes with declared write access but no `ContentApiGrantExtension` anywhere in their hierarchy (#197) |
| `Tasks/CheckGrantExtensionReachabilityTaskTest.php` | Adapter-only: both diagnostics' findings render to stdout in the expected shape |
| `Write/WriteApplicatorTest.php` | Trusted channel setting a polymorphic Class column directly |
| `Write/RecordWriterTest.php` | `write()`'s rollback pre-image capture: plain/has_one/composite/polymorphic-has_one field resolution; the `ACTION_VERB_MISSING` warning for a write-granted class missing the `action` verb (#198) |
| `Verify/OwnedTreeWalkerTest.php` | `$owns` tree walking: has_many/has_one branches, cycle guard, depth cap, diamond ownership resolved to shallowest depth, unversioned intermediates walked through (not pruned), misconfigured-relation logging |
| `ContentApiTestCase.php` + `Stub/*.php` | Shared fixture/registry/token plumbing and test DataObjects |

## Gotchas

> **A stray `app/` doesn't belong in this checkout.** `.gitignore` excludes `/app/`. If an
> `app/code/Page.php`/`PageController.php` scaffold lands on disk here (silverstripe/cms's
> `tests/bootstrap/app.php` creates one during a standalone `phpunit` run), delete it — a vendor
> module has no business defining a `Page` class. A full class-manifest rebuild (`dev/build`, an
> unscoped `phpunit`/`phpstan` run, or the flush above) fatals with `There are two files
> containing the "Page" class` for as long as it sits there. Module-scoped `phpcs`/`phpstan
> analyse <file>` don't boot the kernel, so this is easy to miss on a narrow check and only
> surfaces on a full run.

- The DDEV config manifest cache lives at `/tmp/silverstripe-cache-<hash>` **inside the web
  container**, not a project-relative path — clear it after editing any `_config/*.yml` before a
  CLI `phpunit`/`sake` run picks up the change.

- **A published release tag is immutable — never re-cut a tag that's already gone out.** `git
  fetch --tags` (which `composer update` runs internally, without `--force`) refuses to move a
  tag it already has locally, so re-pointing a published tag breaks `composer update` for every
  existing source install of that release with a hard `! [rejected] ... (would clobber existing
  tag)` — and it's not just a fetch error: the release number now silently refers to a different
  commit for anyone who already has the old one cached. Confirmed live after the 2026-08-07
  branch-1/branch-2 split re-cut the shared `1.x` tag history — see
  [Installation](00_installation.md#recovering-a-source-install-from-before-2026-08-07) for the
  recovery steps a project stuck on the stale tag needs (#193). If a tag genuinely needs to be
  redone, cut a new patch version instead of moving the old one.

- **A test touching a real framework class (not an `ApiTest*` stub) inherits the host project's
  own exposure config, not a clean slate.** `ContentApiTestCase::setUp()` already grants
  `api_access` explicitly to every `ApiTest*` stub (for a different, config-manifest-reliability
  reason — `TestOnly` classes in a vendored module run aren't reliably in the manifest, see the
  comment there), which incidentally means a stub was never at risk of this leak in the first
  place — no host project maps an `ApiTest*` class in its own `content-api.yml`. A class the host
  project also maps (e.g. `SilverStripe\Assets\File`/`Image`) is different: it still carries
  whatever that host declared, layered underneath. #186 was this: one testbed granted `Image` its own
  `api_access` and the other didn't, so two `AssetsTest` cases that assumed `Image` carried no
  grant passed on one host and failed on the other — a host-config difference, not a module
  regression. `ContentApiTestCase` pins `File` to `api_access: false` and removes `Image`'s own
  `api_access` and `content_api_access` from the merged config for this reason, so `Image`
  governs through `File` (an explicit `Image: false` would itself be a deny). Any new test that
  needs a real (non-stub) framework class to start from a known state should pin or remove that
  class's keys explicitly rather than relying on either testbed's own config. The `File` pin only
  neutralizes a host that grants through `api_access` — a host granting `File` via
  `content_api_access` (checked first, always wins) or `discovery_roots`/`discovery_write_policy`
  would still leak in; neither testbed does either today.

## Keeping `schema/endpoints.json` in sync with the MCP server

[`schema/endpoints.json`](../../schema/endpoints.json) is the **source of truth** for the
companion MCP server's tool definitions (`dynamic/silverstripe-content-api-mcp`) — every
endpoint's name, description, method, path, and input schema is authored here, then copied
verbatim into the MCP repo. When you add or change an endpoint:

1. Update `schema/endpoints.json` (and bump its `version` field).
2. In the MCP repo, run `scripts/sync-spec.sh [path-to-this-module-checkout]` — the path argument
   is optional; the script's default already points at this project's vendor checkout location.
   Pass a path explicitly only if your module checkout lives somewhere else.
3. Review the diff, bump the MCP repo's `pyproject.toml` version, PR, and tag a release so
   consumers pick up the change.

Check the MCP repo's bundled spec version against this module's before assuming they're in sync
— see that repo's `docs/development.md`.
