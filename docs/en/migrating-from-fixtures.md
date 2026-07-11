# Migrating from Populate fixtures to the content API

This guide maps the `dnadesign/silverstripe-populate` + attach-task workflow (as used on
A Million Dreamz and dynamicagency-essentials) onto content API calls.

## Concept map

| Fixture workflow | Content API |
|---|---|
| `FixtureIdentifier: my-id` + `PopulateMergeMatch: [FixtureIdentifier]` | `"externalId": "my-id"` (+ upsert). **Same DB column** — existing populated records are addressable as-is. |
| One YAML file per page owning ElementalArea + elements | One `POST compositions/page` request |
| Attach task (`AttachAmdAreasTask`) wiring area→page by hardcoded page id | `page.match` (`id` / `urlSegment` / `externalId`) — the composition attaches directly |
| `Page` records never in fixtures (merge clobber → `home-2` bumps) | Pages safe to update: `POST batch` (`op: "update"`) or a composition's sparse element upsert; URLSegment collisions surface as warnings |
| `PopulateFileFrom` + `Filename` + AppPopulateFactory second-run fix | `POST assets` (or composition `assets[]`) — hash-identical re-uploads always return the record |
| `=>Class.ref` cross-references (order-sensitive, earlier-only) | `{"externalId": …}` (global) or `{"$ref": …}` (per-request aliases, order-independent) |
| `$palette(N)` / `$button(N, Label)` via PopulateColorResolver | Same tokens; resolution built into the write path, unresolvable tokens fail the write |
| `Populate.enable_publish_recursive` + attach-task `publishRecursive()` | `"publish": "recursive"` per request — publishes page + each element/child explicitly |
| `truncate_objects` (destructive, both stages) | `prune: {enabled: true, scope: managed}` — archives only managed (externalId-bearing) elements missing from the payload |
| Orphan-cleanup tasks (`CleanupAmdStaffOrphansTask`, `remove_elements`) | Same prune, or the archive stage action / a batch `delete` op (`mode: "archive"` default, or `"unpublish"`; `"hard"` is unversioned-classes-only) |
| Scoped Populate re-runs (`Config::modify` + `requireRecords(true)`) | Just re-POST the composition — sparse upserts, no duplicate generations |
| Direct-ORM QA tasks (draft-only writes, unresolved color literals) | Any API write: publish semantics explicit, tokens always resolve |

## Worked example

Fixture (`app/fixtures/amd/about.yml`):

```yml
DNADesign\Elemental\Models\ElementalArea:
  amdAboutArea:
    FixtureIdentifier: amd-about-area
    PopulateMergeMatch: [FixtureIdentifier]
    OwnerClassName: Dynamic\Base\Page\BlockPage

Dynamic\Essentials\Element\SimpleContent:
  amdAboutIntro:
    FixtureIdentifier: amd-about-intro
    PopulateMergeMatch: [FixtureIdentifier]
    Parent: =>DNADesign\Elemental\Models\ElementalArea.amdAboutArea
    Sort: 1
    Title: 'Who We Are'
    HTML: '<p>…</p>'
    BackgroundColor: '$palette(0)'
```

…plus an `area_map` entry and an attach-task run becomes:

```bash
curl -X POST -H "X-Silverstripe-Apitoken: $TOKEN" -H "Content-Type: application/json" \
  https://site.test/content-api/v1/compositions/page -d '{
  "page": { "match": { "urlSegment": "about" } },
  "publish": "recursive",
  "elements": [
    { "class": "SimpleContent", "externalId": "amd-about-intro",
      "fields": { "Title": "Who We Are", "HTML": "<p>…</p>",
                  "BackgroundColor": "$palette(0)" } }
  ]
}'
```

The area is resolved from the page (created if missing), `Sort` comes from array order,
and the response reports created/updated per element plus draft/live stage state.

## Existing populated sites

Nothing to migrate: the API's `ExternalIdentifierExtension` declares the identical
`FixtureIdentifier Varchar(100)` column spec, so applying both extensions merges cleanly
and every record your fixtures created responds to `ext:` lookups immediately.

Recommended transition:

1. Install + configure the module (registry, api_access, permissions, token).
2. Keep existing fixtures for full local rebuilds if you like — the two systems share the
   identity column and can coexist.
3. New content work goes through the API; stop writing new fixture files and attach-task
   entries.
4. Fixture drift ends: what the API wrote *is* the source of truth, readable back via
   `GET records/...` and `GET schema/...`.

## Gotchas carried over (and neutralized)

- **Never full-copy pages** — the API has no populate-style field-map copy; only sent
  fields change.
- **Publishing** — elements need individual publishing; `publish: recursive` on a
  composition does this for you. Single-record writes default to draft (`publish: none`),
  so an invisible-content state is explicit, visible in every response's `stage` block.
- **Color tokens on ORM writes** — the populate resolver only ran inside PopulateTask;
  the API resolves tokens on every write, and fails loudly when a token can't resolve.
- **Second-run image refs** — `populateFile()`'s bare-`true` bug doesn't exist here;
  re-uploads always return the full record.
