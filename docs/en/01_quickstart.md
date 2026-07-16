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

## 2. Apply the external-id extension

Apply to classes the API should upsert (same column spec as
`recipe-silverstripe-essentials-fixtures` — legacy-populated sites are addressable as-is):

```yml
SilverStripe\CMS\Model\SiteTree:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
DNADesign\Elemental\Models\BaseElement:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
SilverStripe\Assets\File:
  extensions: ['Dynamic\ContentApi\Identity\ExternalIdentifierExtension']
```

## 3. Grant permissions and mint a token

Grant *Access the content API* (`CONTENT_API_ACCESS`) and, for population endpoints, *Use
content population endpoints* (`CONTENT_API_POPULATE`) to the service account's group, then:

```bash
sake tasks:MintContentApiToken --email=agent@example.com
```

The plaintext token is printed once — see [Authentication](03_authentication.md) for storage
and rotation.

## 4. Call it

`X-Silverstripe-Apitoken` header on every request, both surfaces:

```bash
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/content-api/v1/schema/site
curl -H "X-Silverstripe-Apitoken: $TOKEN" https://site.test/api/ElementContent
```

`schema/site` is the recommended first call — it reports exposed classes, detected
integrations, and whether population endpoints are enabled for the current environment. See
[Schema introspection](11_schema-introspection.md).

## Next

- [Write payloads](06_write-payloads.md) for the `fields`/`relations` shape
- [Page compositions](08_page-compositions.md) to write a whole page atomically
- [Migrating from fixtures](13_migrating-from-fixtures.md) if you're coming from Populate YAML
