# Assets

`POST assets` / `GET assets/$ID` — first-class asset ingestion, the API replacement for
Populate's `PopulateFileFrom` handling. Population-domain endpoint: requires
`CONTENT_API_POPULATE` and passes [environment gating](04_security-model.md#environment-gating).

## `POST assets`

```json
{ "filename": "hero.jpg", "folder": "about", "base64": "...", "title": "Hero image",
  "externalId": "about-hero", "conflict": "overwrite", "publish": true }
```

| Key | Required | Default | Notes |
|---|---|---|---|
| `filename` | yes | — | Basename is used regardless of any path segments passed in |
| `folder` | no | none (root) | May not contain `..` (`400 PAYLOAD_INVALID`) |
| `base64` | one of `base64`/`filePath` | — | Base64-encoded file content |
| `filePath` | one of `base64`/`filePath` | — | **MCP-client-resolved only** — an MCP client reads the local file and base64-encodes it before sending; the upstream API itself never receives a `filePath` field. Not applicable to a direct HTTP caller, which must always send `base64` |
| `title` | no | — | Sets `File.Title` |
| `externalId` | no | — | Sets the external-id column if the resolved class supports it |
| `conflict` | no | `overwrite` | `overwrite`, `skip`, or `rename` — see below |
| `publish` | no | `true` | Whether to `publishSingle()` after write |

An empty binary is `502 ASSET_READ_FAILED`. The target class is resolved from the file
extension (`File::get_class_for_file_extension()`), or from an existing file's class at the
same path when `conflict` is `skip`/`overwrite`.

### Conflict modes

When a file already exists at `folder/filename`:

| Mode | Behavior |
|---|---|
| `overwrite` (default) | Replace content on the existing record. **Skipped** (no store write) when the uploaded content's SHA-1 hash matches the existing file's — same content is a no-op beyond metadata |
| `skip` | Return the existing record untouched, ignoring the new binary entirely |
| `rename` | Store under a deduplicated filename as a **new** record — never reuses the existing one |

### Hash-skip always returns the full record

Unlike Populate's `populateFile()` (which returns bare `true` on a hash match, breaking
second-run `=>Image` references — the fixtures-recipe bug this design specifically avoids), a
hash-identical re-upload here **always returns the full File record** with `existed: true`, so
the caller can wire relations on every run regardless of how many times the same asset is sent.

## `GET assets/$ID`

Numeric id or `ext:<external-id>`. Returns the record plus `url` and `hash`.

## In compositions

`compositions/page`'s `assets[]` array uses the identical shape (plus a `ref` alias for
`{"$ref": "..."}` resolution within the same request) — see
[Page compositions](08_page-compositions.md#assets).
