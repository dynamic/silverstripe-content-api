# Upstream support workstream — colymba/silverstripe-restfulapi

This module deliberately builds on the silverstripeltd-maintained line of
`colymba/silverstripe-restfulapi` and commits to supporting it. The gaps below are
handled inside this module today (noted per item) and proposed upstream so every
consumer benefits. File against
[silverstripeltd/silverstripe-restfulapi](https://github.com/silverstripeltd/silverstripe-restfulapi)
(and cross-reference [colymba/silverstripe-restfulapi](https://github.com/colymba/silverstripe-restfulapi)
where relevant).

## 1. Opt-in hashed token storage — filed: [silverstripeltd#2](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/2)

`TokenAuthenticator` stores the API token in plaintext (`Member.ApiToken`
Varchar(160)) — anyone with CMS or DB access to Member records can read live
tokens. Proposal: opt-in config to store a sha256 hash, returning the plaintext
once at mint time.

Reference implementation: this module's 1.0.0 authenticator —
`git show 1.0.0:src/Auth/TokenAuthenticator.php` (sha256 at rest, constant
lookup by hash, plaintext returned once, throttled auto-refresh).

*Until upstream:* documented caveat; token minting task warns about plaintext.

## 2. Native write-field allowlist — filed: [silverstripeltd#3](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/3)

`DefaultQueryHandler::updateModel()` applies **every key** in the JSON payload;
`api_fields` gates reads only. A GET-then-PUT-verbatim round trip can also detach
`_many` relations (they're applied `removeAll()` + `add()`). Proposal: honor an
`api_writable_fields` / `api_writable_relations` config in `updateModel()`.

*Until upstream:* `Dynamic\ContentApi\Write\WriteGuardExtension` (this module) is
the stopgap and the proof-of-shape — same config keys, enforced via the
`onBeforeDeserialize` / `onBeforeWrite` hooks.

## 3. Parameterized token queries — filed: [silverstripeltd#4](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/4)

`TokenAuthenticator::getOwner()` / `validateAPIToken()` / `lostPassword()` build
raw SQL strings with `Convert::raw2sql()` (e.g. `"\"ApiToken\"='$SQL_token'"`).
Proposal: `Member::get()->filter([$column => $token])` — parameterized, and
portable across DB adapters.

## 4. DELETE should return 204 — filed: [silverstripeltd#5](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/5)

`deleteModel()` returns a null body with HTTP 200; the source already carries
`@todo Respond with a 204 status message on success?`
(`DefaultQueryHandler.php:484`). Proposal: implement the todo (opt-in config if
BC is a concern).

## 5. `api/auth/logout` revokes any member's token unauthenticated — filed: [silverstripeltd#6](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/6)

`TokenAuthenticator::logout()` looks up the Member by the `email` request var
and expires their token with no auth check (the `auth/$Action` route isn't
gated by `authentication_policy`). Any anonymous caller can DoS a known service
account's token.

*Until upstream:* README warns to restrict `/api/auth/*` at the edge and
provision service tokens via the `MintContentApiToken` task rather than login.

## 6. Config to disable the query-var token fallback

`getOwner()`/`authenticate()` always accept `?token=` as a fallback to the
`X-Silverstripe-Apitoken` header; tokens in URLs leak into access logs and
browser history. Proposal: a config flag to disable the query-var path.

*Until upstream:* this module's `/content-api/v1` surface is **header-only** (it
resolves the token itself rather than delegating the query-var-accepting
`authenticate()`), so the fallback is closed on our endpoints; colymba's `/api`
surface still accepts it.

## 7. `feature/v5` calls 4 methods removed in SilverStripe 4+ — filed: [silverstripeltd#7](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/7)

`feature/v5` (this module's `ss5` branch depends on it) targets `silverstripe/framework: ^5.2`,
but `TokenAuthenticator::login()`/`logout()` call `$member->login()`/`$member->logout()`, and
`RESTfulAPI::api_access_config_check()` / `DefaultSerializer::formatDataObject()` call
`$object->stat(...)` — all removed in SilverStripe 4+. This repo's own
`feature/cms-6-compatibility` branch already carries the correct fix for all four
(`IdentityStore::logIn()`/`logOut()`, `config()->get()`), with no SS6-only dependency — the fix
would work unchanged on `feature/v5`.

*Until upstream:* `ss5`'s `composer.json` declares a patch (via `cweagans/composer-patches`,
`patches/colymba-restfulapi-ss5-removed-methods.patch`) applying the same four-line fix on top of
`feature/v5`. Drop the patch once upstream backports it, or once a tagged release supersedes the
branch entirely.

## Design note — our auth adapter does not call colymba's `authenticate()`

Items 1, 5 and colymba's lax token-expiry window (`ApiTokenExpire > now −
tokenLife`) plus its per-request session login are all side effects of
`TokenAuthenticator::authenticate()`. This module's controller instead uses
`getOwner()` (token → Member, no session) and enforces strict expiry itself, so
the `/content-api/v1` surface is stateless and header-only regardless of
upstream. colymba still owns token storage, minting, the `api/auth/*`
endpoints, generic CRUD, the serializer and ACL — the module exercises all of
those; it just declines the one method whose side effects it can't accept.
