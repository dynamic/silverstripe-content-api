<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Control\Handlers\RecordUpdateHandler;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedDeniedSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestStageGatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

/**
 * #232: `PATCH records/$ClassRef/$ID` updates one record's fields with the
 * checks a batch `update` op has, outside the population gate.
 */
class RecordUpdateTest extends ContentApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // An ACCESS-only token: no CONTENT_API_POPULATE.
        $this->token = $this->mintTokenFor('apiUser');
    }

    private function patch(string $path, array $body): \SilverStripe\Control\HTTPResponse
    {
        return $this->apiPatch($path, $body, $this->token);
    }

    public function testUpdatesOneRecordWhilePopulationIsClosed(): void
    {
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        // The same token and config are refused by the population surface.
        $batch = $this->apiPost('batch', ['operations' => [[
            'op' => 'update',
            'class' => 'ApiTest',
            'id' => (int) $record->ID,
            'fields' => ['Title' => 'Via batch'],
        ]]], $this->token);
        $this->assertSame(403, $batch->getStatusCode());

        $response = $this->patch("records/ApiTest/{$record->ID}", ['fields' => ['Title' => 'Patched']]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decode($response);
        $this->assertSame('Patched', $body['data']['fields']['Title']);
        $this->assertSame('updated', $body['meta']['operation']);
        $this->assertSame('Patched', ApiTestObject::get()->byID($record->ID)->Title);
    }

    public function testAnExternalIdAddressesTheRecord(): void
    {
        $response = $this->patch('records/ApiTest/ext:alpha', ['fields' => ['Title' => 'By key']]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('By key', ApiTestObject::get()->filter('FixtureIdentifier', 'alpha')->first()->Title);
    }

    public function testADisabledEnvironmentRefuses(): void
    {
        Config::modify()->merge(RecordUpdateHandler::class, 'disabled_environments', ['dev']);
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->patch("records/ApiTest/{$record->ID}", ['fields' => ['Title' => 'No']]);

        $body = $this->assertErrorCode($response, 'ENV_FORBIDDEN', 403);
        $this->assertStringContainsString('disabled_environments', $body['error']['details'][0]['config']);
        $this->assertSame('Alpha', ApiTestObject::get()->byID($record->ID)->Title);
    }

    public function testItNeedsContentApiAccess(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch(
            "records/ApiTest/{$record->ID}",
            ['fields' => ['Title' => 'No']],
            $this->mintTokenFor('noAccessUser')
        );

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    /**
     * #236: a subclass that denies its own access is refused through its
     * ancestor's ref.
     */
    public function testASubclassDenyIsHonoured(): void
    {
        $record = ApiTestFingerprintRelatedDeniedSubclassObject::create(['Title' => 'Denied']);
        $record->write();

        $response = $this->patch("records/ApiTestFingerprintRelated/{$record->ID}", ['fields' => ['Title' => 'No']]);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
        $this->assertSame('Denied', ApiTestFingerprintRelatedDeniedSubclassObject::get()->byID($record->ID)->Title);
    }

    /**
     * #235: publish single honours a canPublish() stricter than canEdit().
     */
    public function testPublishSingleHonoursCanPublish(): void
    {
        $record = Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $record = ApiTestStageGatedObject::create(['Title' => 'Gated']);
            $record->write();

            return $record;
        });

        $response = $this->patch(
            "records/ApiTestStageGated/{$record->ID}",
            ['fields' => ['Title' => 'Out'], 'publish' => 'single']
        );

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
        $this->assertNull(Versioned::get_by_stage(ApiTestStageGatedObject::class, Versioned::LIVE)->byID($record->ID));
    }

    public function testAWriteWarningIsReportedInMeta(): void
    {
        $record = $this->createVersioned('Before');
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,update');

        $response = $this->patch("records/ApiTestVersioned/{$record->ID}", ['fields' => ['Title' => 'Draft']]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('ACTION_VERB_MISSING', $this->decode($response)['meta']['warnings'][0]['code']);
    }

    public function testAReadOnlyClassAndAProtectedFieldAreRefused(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $this->assertErrorCode(
            $this->patch("records/ApiTest/{$record->ID}", ['fields' => ['ClassName' => ApiTestObject::class]]),
            'READONLY_FIELD',
            422
        );

        Config::modify()->set(ApiTestObject::class, 'api_access', 'read');
        $this->assertErrorCode(
            $this->patch("records/ApiTest/{$record->ID}", ['fields' => ['Title' => 'No']]),
            'FORBIDDEN_CLASS',
            403
        );
        $this->assertSame('Alpha', ApiTestObject::get()->byID($record->ID)->Title);
    }

    public function testPublishSingleNeedsTheActionVerb(): void
    {
        $record = $this->createVersioned('Before');
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,update');

        $response = $this->patch(
            "records/ApiTestVersioned/{$record->ID}",
            ['fields' => ['Title' => 'After'], 'publish' => 'single']
        );

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
        $this->assertSame('Before', $this->liveTitle($record->ID));
        $this->assertSame('Before', ApiTestVersionedObject::get()->byID($record->ID)->Title, 'nothing written');
    }

    public function testPublishSinglePublishesAndNoneStaysOnDraft(): void
    {
        $record = $this->createVersioned('Before');

        $draft = $this->patch("records/ApiTestVersioned/{$record->ID}", ['fields' => ['Title' => 'Draft']]);
        $this->assertSame(200, $draft->getStatusCode(), (string) $draft->getBody());
        $this->assertSame('Before', $this->liveTitle($record->ID));

        $live = $this->patch(
            "records/ApiTestVersioned/{$record->ID}",
            ['fields' => ['Title' => 'Live'], 'publish' => 'single']
        );
        $this->assertSame(200, $live->getStatusCode(), (string) $live->getBody());
        $this->assertSame('Live', $this->liveTitle($record->ID));
    }

    /**
     * @dataProvider malformedBodyProvider
     */
    public function testAMalformedBodyIsRefused(array $body): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $this->assertErrorCode($this->patch("records/ApiTest/{$record->ID}", $body), 'PAYLOAD_INVALID', 400);
        $this->assertSame('Alpha', ApiTestObject::get()->byID($record->ID)->Title);
    }

    public static function malformedBodyProvider(): array
    {
        return [
            'relations' => [['fields' => ['Title' => 'x'], 'relations' => ['Tags' => []]]],
            'no fields' => [['publish' => 'none']],
            'empty fields' => [['fields' => []]],
            'recursive publish' => [['fields' => ['Title' => 'x'], 'publish' => 'recursive']],
            'externalId' => [['fields' => ['Title' => 'x'], 'externalId' => 'rekey']],
            'dryRun' => [['fields' => ['Title' => 'x'], 'dryRun' => true]],
            'a list body' => [[['Title' => 'x']]],
        ];
    }

    public function testANonObjectBodyIsRefused(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        foreach (['', 'null', '"text"', '[]'] as $raw) {
            $response = $this->patchRaw("records/ApiTest/{$record->ID}", $raw);
            $this->assertSame(400, $response->getStatusCode(), $raw);
        }
    }

    private function patchRaw(string $path, string $raw): \SilverStripe\Control\HTTPResponse
    {
        return $this->mainSession->sendRequest('PATCH', "content-api/v1/{$path}", [], [
            'X-Silverstripe-Apitoken' => $this->token,
            'Content-Type' => 'application/json',
        ], null, $raw);
    }

    private function createVersioned(string $title): ApiTestVersionedObject
    {
        return Versioned::withVersionedMode(function () use ($title) {
            Versioned::set_stage(Versioned::DRAFT);
            $record = ApiTestVersionedObject::create(['Title' => $title]);
            $record->write();
            $record->publishSingle();

            return $record;
        });
    }

    private function liveTitle(int $id): ?string
    {
        return Versioned::get_by_stage(ApiTestVersionedObject::class, Versioned::LIVE)->byID($id)?->Title;
    }
}
