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
| `publish` | no | `true` | Whether to `publishSingle()` after write. A boolean, `1`/`0`, or the strings `"true"`/`"false"`/`"1"`/`"0"` (multipart form fields included); anything else is `400 PAYLOAD_INVALID` |

An empty binary is `502 ASSET_READ_FAILED`. The target class is resolved from the file
extension (`File::get_class_for_file_extension()`), or from an existing file's class at the
same path when `conflict` is `skip`/`overwrite`. Non-image types (PDF, DOCX, etc.) resolve to
plain `File` and upload the same way images do — there is no hardcoded `Image`-only path (#204).
An extension outside `File.allowed_extensions` is `422 VALIDATION_FAILED`, not a silent no-op or
an unmapped `500` — a caller must never see success (or a `File` id) for a write that didn't
actually land.

### Conflict modes

When a file already exists at `folder/filename`:

| Mode | Behavior |
|---|---|
| `overwrite` (default) | Replace content on the existing record. **Skipped** (no store write) when the uploaded content's SHA-1 hash matches the existing file's — same content is a no-op beyond metadata |
| `skip` | Return the existing record untouched: the new binary, `title`, `externalId` and `publish` are all ignored |
| `rename` | Store under a deduplicated filename as a **new** record — never reuses the existing one |

The existing file is looked up on the draft stage, so a draft-only file at the path counts.

### Authorization

The verbs an upload needs depend on what it does to which record:

- **New file** (nothing at the path, or `rename`): `create` on the class the extension maps to,
  plus `canCreate()` with the target folder as `Parent`. A folder that does not exist yet also
  needs `Folder::canCreate()`.
- **`overwrite`**: `update` on the existing file's class plus `canEdit()`.
- **`skip`**: `read` only.
- **Publishing** (`publish` true; the default for `POST assets`): `action` on the class written. When `publish`
  is not sent and the class lacks `action`, the file is written draft-only and the response's
  `data.warnings` carries `ACTION_VERB_MISSING`. An explicit `"publish": true` without `action`
  is `403 FORBIDDEN_CLASS`.

Verbs are read from the asset's governing class: the nearest class in its ancestry (`Image` →
`File`) that declares any. Composition `assets[]` entries use the same rules, except that an
entry without its own `publish` follows the composition's mode (draft-only under `"none"`, the
composition default).

The path is matched the way SilverStripe stores it: folder segments through `FolderNameFilter`
and the filename through `FileNameFilter`, so re-uploading `Team Photo.png` into `About Us`
finds the `About-Us/Team-Photo.png` the first upload stored instead of creating a `-v2` sibling.

An upload's `externalId` stays on one file. A key already held by a file at another path, or a
path whose file already carries a different key, is `409 ASSET_CONFLICT`. Upload to the path the
key lives at, or use a new key. `rename` always writes a new file, so a `rename` upload with a key
already on any file is a 409 too, including a re-run of the same request (in a composition that
rolls the whole composition back): use `overwrite` or `skip` for keyed, re-runnable uploads.

`AssetService.verify_content_type` (default `false`) adds a content check: with it on, a file
whose extension maps to `Image` must sniff as `image/*`, or the upload is `422
VALIDATION_FAILED`. Off by default, matching core's extension-only upload validation. SVG, if a
project allows the extension, is stored unsanitised either way.

Multipart uploads report PHP's own limits: a file over `upload_max_filesize`, or a body over
`post_max_size`, is `413 PAYLOAD_TOO_LARGE` naming the limit (a `post_max_size` of `0` is no
limit); other PHP upload errors, and a `file[]` array part, are `400 PAYLOAD_INVALID` with the
reason.

### Hash-skip always returns the full record

Unlike Populate's `populateFile()` (which returns bare `true` on a hash match, breaking
second-run `=>Image` references — the fixtures-recipe bug this design specifically avoids), a
hash-identical re-upload here **always returns the full File record** with `existed: true`, so
the caller can wire relations on every run regardless of how many times the same asset is sent.

## `GET assets/$ID`

Numeric id or `ext:<external-id>`. Returns the record plus `url`, `visibility` and `hash`.

`url` is the file's URL with no session grant. `visibility` says whether it is fetchable without
logging in: `public` (published) or `protected` (draft-only, or restricted), where the URL only
works for a CMS session allowed to view the file. Both are `null` for a record with no stored
file. Upload responses carry the same fields.

## In compositions

`compositions/page`'s `assets[]` array uses the identical shape (plus a `ref` alias for
`{"$ref": "..."}` resolution within the same request) — see
[Page compositions](08_page-compositions.md#assets).
