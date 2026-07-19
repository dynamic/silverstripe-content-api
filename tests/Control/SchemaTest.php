<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;

class SchemaTest extends ContentApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('apiUser');
    }

    public function testSiteSchemaListsExposedClasses(): void
    {
        $body = $this->decode($this->apiGet('schema', $this->token));

        $this->assertNull($body['error']);

        $classes = $body['data']['classes'];
        $this->assertArrayHasKey('ApiTest', $classes);
        $this->assertContains('read', $classes['ApiTest']['access']);
        $this->assertTrue($classes['ApiTest']['externalId']);
        $this->assertFalse($classes['ApiTest']['versioned']);
        $this->assertTrue($classes['ApiTestVersioned']['versioned']);
        $this->assertTrue($classes['ApiTestElement']['element']);
        $this->assertTrue($classes['BlockPageStub']['page']);

        $this->assertTrue($body['data']['integrations']['elemental']);
        $this->assertTrue($body['data']['integrations']['linkfield']);
        $this->assertTrue($body['data']['integrations']['restfulapi']);
        $this->assertArrayHasKey('populationEnabled', $body['data']);

        // Generic CRUD pointer: the colymba surface.
        $crud = $body['data']['crud'];
        $this->assertSame('colymba/silverstripe-restfulapi', $crud['provider']);
        $this->assertSame('api', $crud['route']);
        $this->assertSame('api/auth/login', $crud['auth']);
        $this->assertIsArray($crud['models']);
    }

    public function testSiteSchemaOmitsUnexposedClasses(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);

        $body = $this->decode($this->apiGet('schema/site', $this->token));

        $this->assertArrayNotHasKey('ApiTest', $body['data']['classes']);
    }

    public function testClassSchemaDescribesPayloadContract(): void
    {
        $body = $this->decode($this->apiGet('schema/ApiTestElement', $this->token));

        $this->assertNull($body['error']);
        $data = $body['data'];

        $this->assertSame('FixtureIdentifier', $data['externalIdField']);
        $this->assertTrue($data['versioned']);

        // Fields carry type + writability.
        $this->assertTrue($data['fields']['Title']['writable']);
        $this->assertArrayNotHasKey('ID', $data['fields']);
        $this->assertArrayNotHasKey('FixtureIdentifier', $data['fields']);

        // has_one payload kinds drive agent payload construction.
        $this->assertSame('assetRef', $data['hasOne']['Photo']['payload']);
        $this->assertSame('link', $data['hasOne']['Cta']['payload']);

        // has_many present; writability reflects api_writable_relations.
        $this->assertArrayHasKey('Items', $data['hasMany']);
        $this->assertFalse($data['hasMany']['Items']['writable']);

        Config::modify()->set(
            \Dynamic\ContentApi\Tests\Stub\ApiTestElement::class,
            'api_writable_relations',
            ['Items']
        );
        $body = $this->decode($this->apiGet('schema/ApiTestElement', $this->token));
        $this->assertTrue($body['data']['hasMany']['Items']['writable']);
    }

    public function testClassSchemaEnumValues(): void
    {
        $body = $this->decode($this->apiGet('schema/ApiTestVersioned', $this->token));

        $this->assertSame(
            ['Draft', 'Review', 'Final'],
            array_values($body['data']['fields']['Status']['values'])
        );
    }

    public function testClassSchemaReflectsAllowlistPolicy(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_write_policy', 'allowlist');
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $body = $this->decode($this->apiGet('schema/ApiTest', $this->token));

        $this->assertTrue($body['data']['fields']['Title']['writable']);
        $this->assertFalse($body['data']['fields']['Rank']['writable']);
    }

    public function testClassSchemaSurfacesComputedFieldFlag(): void
    {
        Config::modify()->set(
            ApiTestObject::class,
            'api_computed_fields',
            ['Title' => 'Overwritten from ParentPage.Title on save']
        );

        $body = $this->decode($this->apiGet('schema/ApiTest', $this->token));
        $fields = $body['data']['fields'];

        $this->assertTrue($fields['Title']['computed']);
        $this->assertSame('Overwritten from ParentPage.Title on save', $fields['Title']['note']);
        // Advisory only — writability itself is untouched.
        $this->assertTrue($fields['Title']['writable']);
        // A field with no honesty flag configured carries neither key.
        $this->assertArrayNotHasKey('computed', $fields['Rank']);
        $this->assertArrayNotHasKey('importOwned', $fields['Rank']);
    }

    public function testClassSchemaSurfacesImportOwnedFieldFlag(): void
    {
        Config::modify()->set(
            ApiTestObject::class,
            'api_import_owned_fields',
            ['Rank' => 'Owned by the nightly import; overwritten on next sync']
        );

        $body = $this->decode($this->apiGet('schema/ApiTest', $this->token));
        $fields = $body['data']['fields'];

        $this->assertTrue($fields['Rank']['importOwned']);
        $this->assertSame(
            'Owned by the nightly import; overwritten on next sync',
            $fields['Rank']['note']
        );
        $this->assertArrayNotHasKey('computed', $fields['Rank']);
    }

    public function testClassSchemaHonestyFlagsAreIndependentWhenBothConfigured(): void
    {
        // A field can be both computed and import-owned at once (e.g. a
        // value the model recomputes from an imported source field) — the
        // two flags must not be mutually exclusive.
        Config::modify()->set(ApiTestObject::class, 'api_computed_fields', ['Title']);
        Config::modify()->set(
            ApiTestObject::class,
            'api_import_owned_fields',
            ['Title' => 'Sourced from the nightly import']
        );

        $body = $this->decode($this->apiGet('schema/ApiTest', $this->token));
        $field = $body['data']['fields']['Title'];

        $this->assertTrue($field['computed']);
        $this->assertTrue($field['importOwned']);
        $this->assertSame('Sourced from the nightly import', $field['note']);
    }

    public function testClassSchemaHonestyFlagsAcceptBareFieldList(): void
    {
        // A bare list of field names (no notes) normalizes to a flag with
        // no `note` key, rather than erroring or emitting `note: null`.
        Config::modify()->set(ApiTestObject::class, 'api_computed_fields', ['Title']);

        $body = $this->decode($this->apiGet('schema/ApiTest', $this->token));
        $field = $body['data']['fields']['Title'];

        $this->assertTrue($field['computed']);
        $this->assertArrayNotHasKey('note', $field);
    }

    public function testPolymorphicHasOneWritabilityReflectsCompanionClassColumn(): void
    {
        // #25: a polymorphic has_one's writability must account for the
        // companion Class column too, not just the FK — otherwise the
        // schema endpoint tells a client Owner is writable when a real
        // write would still be rejected over the protected Class column.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'Owner']);
        Config::modify()->set(ApiTestPolyObject::class, 'api_protected_fields', ['OwnerClass']);

        $body = $this->decode($this->apiGet('schema/ApiTestPoly', $this->token));

        $this->assertFalse(
            $body['data']['hasOne']['Owner']['writable'],
            'Owner must report unwritable while its companion Class column is protected'
        );
    }

    /**
     * Regression coverage for #34: a has_one declared via the array/
     * `multirelational` form must report identically to the plain-string
     * polymorphic form in schema introspection — `hasOne()` normalizes both
     * to the same bare class string before SchemaService ever sees them.
     *
     * Also asserts the multirelational-only `{Name}Relation` companion
     * column (a code-review finding: DBPolymorphicRelationAwareForeignKey's
     * own extra composite field, absent from the plain polymorphic form)
     * is excluded from the standalone `fields` map — it isn't a payload
     * shape a client can write (see WriteApplicator's guard), so
     * advertising it would be misleading.
     */
    public function testMultiRelationalPolymorphicHasOneSchemaMatchesPlainPolymorphic(): void
    {
        $body = $this->decode($this->apiGet('schema/ApiTestMultiRelationalPoly', $this->token));

        $this->assertSame(DataObject::class, $body['data']['hasOne']['Owner']['class']);
        $this->assertTrue($body['data']['hasOne']['Owner']['writable']);
        $this->assertArrayNotHasKey('OwnerClass', $body['data']['fields']);
        $this->assertArrayNotHasKey('OwnerRelation', $body['data']['fields']);
    }

    public function testSchemaRequiresPermission(): void
    {
        $response = $this->apiGet('schema', $this->mintTokenFor('noAccessUser'));

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testUnknownClassRefIs404(): void
    {
        $response = $this->apiGet('schema/Nope', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_CLASS', 404);
    }
}
