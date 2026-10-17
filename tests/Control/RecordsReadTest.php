<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Control\Handlers\RecordsHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Verify\VersionCache;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class RecordsReadTest extends ContentApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('apiUser');
    }

    public function testListReturnsRecords(): void
    {
        $response = $this->apiGet('records/ApiTest', $this->token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // canView filtering runs before total/pagination are computed (#20),
        // so total reflects only the 5 visible records, not the raw 6.
        $this->assertSame(5, $body['meta']['total']);
        $this->assertCount(5, $body['data']);

        $first = $body['data'][0];
        $this->assertSame('ApiTest', $first['classRef']);
        $this->assertArrayHasKey('Title', $first['fields']);
        $this->assertArrayNotHasKey('FixtureIdentifier', $first['fields']);
    }

    public function testListFilters(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest?Title=Beta', $this->token));
        $this->assertCount(1, $body['data']);
        $this->assertSame('Beta', $body['data'][0]['fields']['Title']);

        $body = $this->decode($this->apiGet('records/ApiTest?Title__PartialMatch=Beta', $this->token));
        $this->assertCount(2, $body['data']);

        $body = $this->decode($this->apiGet('records/ApiTest?Rank__GreaterThan=1&Rank__LessThan=99', $this->token));
        $this->assertCount(2, $body['data']);
    }

    public function testListRejectsUnknownFilterField(): void
    {
        $response = $this->apiGet('records/ApiTest?Bogus=1', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_FIELD', 422);
    }

    public function testListRejectsUnknownModifier(): void
    {
        $response = $this->apiGet('records/ApiTest?Title__DropTable=1', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testListSortAndPagination(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest?sort=-Rank&limit=2', $this->token));

        // canView filtering runs before pagination (#20): Secret (Rank 99)
        // is excluded from the visible set entirely, so a full 2-record
        // page comes back — [Beta Prime, Beta] — instead of a short page
        // with Secret silently occupying a slot and then vanishing.
        $this->assertCount(2, $body['data']);
        $this->assertSame(2, $body['meta']['limit']);
        $this->assertSame(5, $body['meta']['total']);
        $this->assertSame('Beta Prime', $body['data'][0]['fields']['Title']);
        $this->assertSame('Beta', $body['data'][1]['fields']['Title']);

        $body = $this->decode($this->apiGet('records/ApiTest?sort=Rank&limit=2&offset=4', $this->token));
        $this->assertSame(4, $body['meta']['offset']);
    }

    public function testReadOneById(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $this->assertSame('Alpha', $body['data']['fields']['Title']);
        $this->assertSame('alpha', $body['data']['externalId']);
        $this->assertArrayHasKey('Buddy', $body['data']['relations']);
    }

    public function testReadOneByExternalId(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest/ext:alpha', $this->token));

        $this->assertSame('Alpha', $body['data']['fields']['Title']);
    }

    public function testAmbiguousExternalIdConflicts(): void
    {
        $response = $this->apiGet('records/ApiTest/ext:dup', $this->token);

        $this->assertErrorCode($response, 'MULTIPLE_MATCHES', 409);
    }

    public function testMissingExternalIdIs404(): void
    {
        $response = $this->apiGet('records/ApiTest/ext:nope', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testMissingIdIs404(): void
    {
        $response = $this->apiGet('records/ApiTest/999999', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testMalformedIdIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTest/abc', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testUnknownClassRefIs404(): void
    {
        $response = $this->apiGet('records/Nope', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_CLASS', 404);
    }

    public function testMemberWithoutApiPermissionIsForbidden(): void
    {
        $token = $this->mintTokenFor('noAccessUser');

        $response = $this->apiGet('records/ApiTest', $token);

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testClassWithoutApiAccessIsForbidden(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);

        $response = $this->apiGet('records/ApiTest', $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testContentApiAccessOverridesApiAccess(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);
        Config::modify()->set(ApiTestObject::class, 'content_api_access', 'read');

        $response = $this->apiGet('records/ApiTest', $this->token);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRecordCanViewIsEnforcedOnReadOne(): void
    {
        $secret = $this->objFromFixture(ApiTestObject::class, 'secret');

        $response = $this->apiGet("records/ApiTest/{$secret->ID}", $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
    }

    public function testVersionedStageReads(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        // Draft (default) sees the record and reports stage state.
        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}", $this->token));
        $this->assertTrue($body['data']['stage']['draft']);
        $this->assertFalse($body['data']['stage']['live']);
        $this->assertSame('draft', $body['meta']['stage']);

        // Live misses the draft-only record.
        $response = $this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->token);
        $this->assertErrorCode($response, 'NOT_FOUND', 404);

        // After publish, live sees it.
        $record->publishSingle();
        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->token));
        $this->assertTrue($body['data']['stage']['live']);
    }

    public function testInvalidStageIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTestVersioned?_stage=nope', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * #244: malformed paging values are refused, not coerced.
     */
    public function testMalformedPagingIsRejected(): void
    {
        foreach (['limit=abc', 'limit=0', 'limit=-5', 'limit=1.9', 'limit=5abc', 'offset=abc', 'offset=-1'] as $query) {
            $response = $this->apiGet('records/ApiTest?' . $query, $this->token);
            $this->assertSame(400, $response->getStatusCode(), $query);
            $this->assertSame('PAYLOAD_INVALID', $this->decode($response)['error']['code'], $query);
        }

        $max = (int) Config::inst()->get(RecordsHandler::class, 'max_limit');
        $body = $this->decode($this->apiGet('records/ApiTest?limit=' . ($max + 1) . '&offset=', $this->token));
        $this->assertSame($max, $body['meta']['limit'], 'an over-large limit is still clamped');
        $this->assertSame(0, $body['meta']['offset']);
    }

    /**
     * #244: an empty filter value filters on the empty string.
     */
    public function testAnEmptyFilterValueMatchesTheEmptyString(): void
    {
        $this->assertCount(0, $this->decode($this->apiGet('records/ApiTest?Title=', $this->token))['data']);

        $blank = ApiTestObject::create(['Title' => '']);
        $blank->write();

        $body = $this->decode($this->apiGet('records/ApiTest?Title=', $this->token));
        $this->assertSame([(int) $blank->ID], array_column($body['data'], 'id'));

        $body = $this->decode($this->apiGet('records/ApiTest?Title__not=', $this->token));
        $this->assertNotContains((int) $blank->ID, array_column($body['data'], 'id'));
        $this->assertCount(5, $body['data']);

        $this->assertErrorCode($this->apiGet('records/ApiTest?Rank=', $this->token), 'PAYLOAD_INVALID', 400);
    }

    /**
     * #244: a draft list primes the version-number cache instead of
     * looking up each row's version, so the query count does not grow
     * with the number of rows the list visits.
     */
    public function testADraftListsQueryCountDoesNotGrowWithItsRows(): void
    {
        // A non-admin token, so canView() runs Versioned's stage check per
        // row rather than an ADMIN short-circuit.
        $token = $this->token;
        $count = function () use ($token): int {
            DataObject::flush_and_destroy_cache();
            $before = $this->questions();
            $response = $this->apiGet('records/ApiTestPage?limit=1', $token);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

            return $this->questions() - $before;
        };

        $this->pages(3);
        $few = $count();
        $this->pages(12);
        $many = $count();

        $this->assertLessThan(5, $many - $few, "{$few} queries for 3 rows, {$many} for 15");
    }

    /**
     * #244: priming batches its ids, so a list larger than one batch reads
     * the same as a small one.
     */
    public function testPrimingBatchesItsIds(): void
    {
        $ids = range(1, 1201);
        VersionCache::prime(ApiTestPage::class, $ids);

        $this->assertNull(Versioned::get_versionnumber_by_stage(ApiTestPage::class, Versioned::LIVE, 1201));
    }

    /**
     * Half published, half draft-only: a draft-only row has no live
     * version, the case the older version cache leaves uncached.
     */
    private function pages(int $count): void
    {
        Versioned::withVersionedMode(function () use ($count) {
            Versioned::set_stage(Versioned::DRAFT);

            for ($i = 0; $i < $count; $i++) {
                $page = ApiTestPage::create(['Title' => 'Listed ' . uniqid()]);
                $page->write();

                if ($i % 2 === 0) {
                    $page->publishSingle();
                }
            }
        });
    }

    /**
     * Statements this connection has run, per MySQL's own counter.
     */
    private function questions(): int
    {
        return (int) DB::query("SHOW SESSION STATUS LIKE 'Questions'")->record()['Value'];
    }
}
