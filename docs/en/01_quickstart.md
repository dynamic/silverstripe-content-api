# Quick start

Four steps to your first authenticated call. See [Configuration reference](02_configuration.md)
for every option used here.

## 1. Expose classes

One map drives both surfaces (deny-by-default: a class must be mapped AND granted
`api_access`):

```yml
Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler:
  models:
    BlockPage: Dynamic\Base\Page\BlockPage
    ElementContent: DNADesign\Elemental\Models\ElementContent
    Image: SilverStripe\Assets\Image

# content-api-only refs (or overrides) go on the module's registry:
Dynamic\ContentApi\Registry\ClassRegistry:
  models:
    ElementalArea: DNADesign\Elemental\Models\ElementalArea

DNADesign\Elemental\Models\ElementContent:
  api_access: 'GET,POST,PUT'            # colymba HTTP verbs; the module maps them to
                                        # read/create/update (plus: delete, action)
  api_writable_fields: [Title, HTML, Sort, ShowTitle]
  extensions:
    - Dynamic\ContentApi\Write\WriteGuardExtension
```

> **SECURITY:** never grant write verbs (`POST,PUT`) in `api_access` without
> `WriteGuardExtension` — see [Security model](04_security-model.md) for why this is mandatory,
> not optional hardening.

## 2. Apply extensions

Apply the external-id extension to classes the API should upsert (same column spec as
`recipe-silverstripe-essentials-fixtures` — legacy-populated sites are addressable as-is), and
`ContentApiGrantExtension` to any class a service account needs to write without holding
`ADMIN`/`SITETREE_EDIT_ALL` (see [Grant extension](04_security-model.md#grant-extension) — it
only grants a class that declares its own `api_access`/`content_api_access`):

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Dynamic\ContentApi\Identity\ExternalIdentifierExtension
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
DNADesign\Elemental\Models\BaseElement:
  extensions:
    - Dynamic\ContentApi\Identity\ExternalIdentifierExtension
    - Dynamic\ContentApi\Security\ContentApiGrantExtension
SilverStripe\Assets\File:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
```

## 3. Grant permissions and mint a token

Grant *Access the content API* (`CONTENT_API_ACCESS`) **and `VIEW_DRAFT_CONTENT`** to the
service account's group — reads default to the draft stage, and without `VIEW_DRAFT_CONTENT`
the account can't read back its own draft-only writes once draft and live diverge (see
[Security model](04_security-model.md#service-account-permissions)). Add *Use content
population endpoints* (`CONTENT_API_POPULATE`) too if the account needs batch/compositions/asset
writes/page actions. These permission codes satisfy the class-level gate and the draft-read
check; `ContentApiGrantExtension` (step 2) is what satisfies the record-level `can*()` gate — a
service account needs both. A task provisions the permission codes in one step:

```bash
sake tasks:SetupContentApiServiceAccount --group="Content API Service Accounts" --member=agent@example.com
sake tasks:MintContentApiToken --email=agent@example.com
```

Add `--populate` to the first command too if the account needs batch/compositions/asset
writes/page actions. `--member` (#124) also find-or-creates that Member and attaches it to the
group in the same step — omit it and the Member step is skipped entirely, matching the older
two-task behavior where a project supplied its own Member. This is a real, repeated step in the
provisioning ritual, not a one-time setup: a DB sync wipes any locally-created service-account
Member (it isn't part of a synced prod snapshot), so most projects re-run `--member` after every
sync rather than passing it only once.

The plaintext token is printed once — see [Authentication](03_authentication.md) for storage and
rotation.

## 4. Call it

`X-Silverstripe-Apitoken` header on every request, both surfaces:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/content-api/v1/schema/site
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/api/ElementContent
```

`schema/site` is the recommended first call — it reports exposed classes, detected
integrations, and whether population endpoints are enabled for the current environment. See
[Schema introspection](11_schema-introspection.md).

> **Before a first population write against a `live`-type target**, check `schema/site`'s
> population-enabled flag (or just confirm `SS_CONTENT_API_ALLOW_POPULATE` is set there) —
> `dev`/`test` never exercises this gate, so a clean local rehearsal gives no warning that a real
> target needs it. See [Configuration](02_configuration.md#environmentgate) (#126).

## Next

- [Write payloads](06_write-payloads.md) for the `fields`/`relations` shape
- [Page compositions](08_page-compositions.md) to write a whole page atomically
- [Migrating from fixtures](13_migrating-from-fixtures.md) if you're coming from Populate YAML
