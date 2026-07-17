# Authentication

Both surfaces share one token model — colymba/silverstripe-restfulapi's `TokenAuthenticator` —
but `/content-api/v1` enforces it more strictly than colymba's own `/api` does.

## Minting tokens

```bash
sake tasks:MintContentApiToken email=agent@example.com
```

`MintApiTokenTask` (command name `MintContentApiToken`) finds the member by `email`
(required — prints an error message and stops if missing or no member matches; a legacy
`BuildTask::run()` on this branch has no exit-code contract, so check the printed output rather
than the process exit code), calls colymba's `TokenAuthenticator::resetToken()` + `getToken()`,
and prints:

```
Token minted for agent@example.com (member #12), expires <ISO-8601 timestamp, mint time + tokenLife>:

  <plaintext-token>

Note: colymba/silverstripe-restfulapi stores tokens in plaintext on the
Member record — anyone with CMS access to Members can read them.
```

The plaintext token is shown **once** — colymba stores only the live value on
`Member.ApiToken` (see the plaintext-storage caveat below). Re-run the task to rotate; there is
no separate revoke command. `resetToken()` replaces any existing token for that member, so
minting doubles as rotation.

Every member gets one token; there is no concept of multiple concurrent tokens per member.

## `/content-api/v1`'s hardened auth check

This module's controller (`ContentApiController::requireAuth()`) does **not** call colymba's
`TokenAuthenticator::authenticate()`. Instead:

- **Header-only.** It requires the configured header (`X-Silverstripe-Apitoken` by default,
  from `TokenAuthenticator.tokenHeader`) to be present at all, then resolves the member via
  `getOwner()` — colymba's `?token=` query-var fallback is never consulted on this surface.
- **No session login.** `getOwner()` performs no session/IdentityStore login; the controller
  calls `Security::setCurrentUser()` directly, keeping the surface stateless.
- **Strict expiry.** Rejected as `TOKEN_EXPIRED` at the advertised `ApiTokenExpire` exactly —
  not colymba's own `authenticate()` grace window (see below).

Auth failures on this surface are `401 UNAUTHENTICATED` (no token / unrecognized token) or
`401 TOKEN_EXPIRED` (expired token) — both machine-readable content-api error codes. Colymba's
own `/api` surface answers `403` with its native shape instead; see
[Error codes](12_error-codes.md).

`GET auth/session` introspects the current token: member, held permission codes, expiry. Useful
to verify a token before relying on it in a script:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/content-api/v1/auth/session
```

## Token lifetime

- `tokenLife` (default `604800`, 7 days) — set on `Colymba\RESTfulAPI\Authenticators\TokenAuthenticator`.
- `autoRefreshLifetime: false` — no activity-based auto-renewal; a token's expiry is fixed at
  mint time. Long-lived service accounts should raise `tokenLife` (e.g. `31536000` for a year)
  in project config and re-mint on that cadence, rather than relying on refresh.

See [Configuration reference](02_configuration.md#colymba-hardening-set-by-_configconfigyml)
for the full table.

## Colymba `/api` surface caveats

Everything below applies **only** to colymba's own `/api` routes (generic CRUD, `api/auth/*`) —
none of it applies to `/content-api/v1`, which sidesteps `authenticate()` entirely (see above).
Tracked and being addressed upstream in [Upstream issues](upstream-issues.md).

- **Plaintext token storage.** `Member.ApiToken` is stored unhashed — anyone with CMS or DB
  access to Member records can read live tokens.
- **`?token=` query-var fallback is always active** on `/api`. Keep tokens out of URLs (access
  logs, browser history, referrer headers).
- **Grace-window expiry.** Colymba's own `authenticate()` treats a token as valid while
  `ApiTokenExpire > now − tokenLife` — a token can remain accepted up to a full `tokenLife`
  *past* its advertised expiry.
- **Session login as a side effect.** Every authenticated `/api` request logs the member into
  the session IdentityStore.
- ⚠️ **`api/auth/logout?email=…` is unauthenticated upstream** — any caller can expire a known
  service account's token (a DoS on integrations). Restrict `/api/auth/*` at the network edge,
  or provision service tokens via `MintContentApiToken` and avoid `logout` for anything you
  depend on. Filed as
  [silverstripeltd#6](https://github.com/silverstripeltd/silverstripe-restfulapi/issues/6).

Treat `/api` as a trusted server-to-server surface until these land upstream. `/content-api/v1`
is unaffected by all five points.
