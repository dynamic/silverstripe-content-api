# Upstream support workstream — colymba/silverstripe-restfulapi

This module deliberately builds on the silverstripeltd-maintained line of
`colymba/silverstripe-restfulapi` and commits to supporting it. The gaps below are
handled inside this module today (noted per item) and proposed upstream so every
consumer benefits. File against
[silverstripeltd/silverstripe-restfulapi](https://github.com/silverstripeltd/silverstripe-restfulapi)
(and cross-reference [colymba/silverstripe-restfulapi](https://github.com/colymba/silverstripe-restfulapi)
where relevant).

## 1. Opt-in hashed token storage

`TokenAuthenticator` stores the API token in plaintext (`Member.ApiToken`
Varchar(160)) — anyone with CMS or DB access to Member records can read live
tokens. Proposal: opt-in config to store a sha256 hash, returning the plaintext
once at mint time.

Reference implementation: this module's 1.0.0 authenticator —
`git show 1.0.0:src/Auth/TokenAuthenticator.php` (sha256 at rest, constant
lookup by hash, plaintext returned once, throttled auto-refresh).

*Until upstream:* documented caveat; token minting task warns about plaintext.

## 2. Native write-field allowlist

`DefaultQueryHandler::updateModel()` applies **every key** in the JSON payload;
`api_fields` gates reads only. A GET-then-PUT-verbatim round trip can also detach
`_many` relations (they're applied `removeAll()` + `add()`). Proposal: honor an
`api_writable_fields` / `api_writable_relations` config in `updateModel()`.

*Until upstream:* `Dynamic\ContentApi\Write\WriteGuardExtension` (this module) is
the stopgap and the proof-of-shape — same config keys, enforced via the
`onBeforeDeserialize` / `onBeforeWrite` hooks.

## 3. Parameterized token queries

`TokenAuthenticator::getOwner()` / `validateAPIToken()` / `lostPassword()` build
raw SQL strings with `Convert::raw2sql()` (e.g. `"\"ApiToken\"='$SQL_token'"`).
Proposal: `Member::get()->filter([$column => $token])` — parameterized, and
portable across DB adapters.

## 4. DELETE should return 204

`deleteModel()` returns a null body with HTTP 200; the source already carries
`@todo Respond with a 204 status message on success?`
(`DefaultQueryHandler.php:484`). Proposal: implement the todo (opt-in config if
BC is a concern).

## 5. (Noted, not filed) Config to disable the query-var token fallback

`getOwner()`/`authenticate()` always accept `?token=` as a fallback to the
`X-Silverstripe-Apitoken` header; tokens in URLs leak into access logs and
browser history. Proposal: a config flag to disable the query-var path.
