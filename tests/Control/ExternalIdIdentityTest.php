<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use SilverStripe\CMS\Model\SiteTree;

/**
 * #240: an external id identifies exactly one record, every way it can be
 * written, and can always be read back.
 */
class ExternalIdIdentityTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');
    }

    private function batch(array $operation): array
    {
        return $this->decode($this->apiPost('batch', ['operations' => [$operation]], $this->adminToken))
            ['data']['results'][0];
    }

    /**
     * Finding 1: neither an update's `externalId` nor `fields.FixtureIdentifier`
     * can take a key another record holds; re-sending a record's own key is fine.
     */
    public function testATakenKeyCannotBeAssigned(): void
    {
        $two = $this->objFromFixture(ApiTestObject::class, 'two');

        $results = [
            $this->batch(['op' => 'update', 'class' => 'ApiTest', 'id' => (int) $two->ID, 'externalId' => 'alpha']),
            $this->batch([
                'op' => 'update',
                'class' => 'ApiTest',
                'id' => (int) $two->ID,
                'fields' => ['FixtureIdentifier' => 'alpha'],
            ]),
            $this->batch(['op' => 'create', 'class' => 'ApiTest', 'fields' => ['FixtureIdentifier' => 'alpha']]),
        ];

        foreach ($results as $result) {
            $this->assertSame('ALREADY_EXISTS', $result['error']['code'], json_encode($result));
        }

        $this->assertSame(1, ApiTestObject::get()->filter('FixtureIdentifier', 'alpha')->count());

        $own = $this->batch([
            'op' => 'update',
            'class' => 'ApiTest',
            'id' => (int) $two->ID,
            'externalId' => (string) $two->FixtureIdentifier,
        ]);
        $this->assertSame('updated', $own['status']);
    }

    public function testAnUnaddressableKeyIsRejected(): void
    {
        foreach (['a/b', 'why?', 'hash#tag'] as $key) {
            $result = $this->batch(['op' => 'create', 'class' => 'ApiTest', 'externalId' => $key]);

            $this->assertSame('PAYLOAD_INVALID', $result['error']['code'], $key);
        }
    }

    /**
     * Finding 2: a percent-encoded `ext:` id resolves.
     */
    public function testAnEncodedExtIdResolves(): void
    {
        $record = ApiTestObject::create(['Title' => 'Spaced', 'FixtureIdentifier' => 'home page']);
        $record->write();

        $response = $this->apiGet('records/ApiTest/ext:home%20page', $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame((int) $record->ID, $this->decode($response)['data']['id']);
    }

    /**
     * Finding 5: createIfMissing matches what it created last run, and
     * refuses a segment SiteTree would rewrite.
     */
    public function testCreateIfMissingIsIdempotentForASegmentSiteTreeNormalises(): void
    {
        $payload = [
            'page' => [
                'match' => ['urlSegment' => 'About Us Today'],
                'createIfMissing' => ['title' => 'About Us Today', 'className' => 'BlockPageStub'],
            ],
            'elements' => [],
        ];

        $first = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));
        $second = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));

        $this->assertSame('created', $first['data']['page']['operation']);
        $this->assertSame('matched', $second['data']['page']['operation']);
        $this->assertSame(1, SiteTree::get()->filter('URLSegment', 'about-us-today')->count());
    }

    public function testCreateIfMissingRefusesASegmentSiteTreeWouldRewrite(): void
    {
        $response = $this->apiPost('compositions/page', [
            'page' => [
                'match' => ['urlSegment' => 'admin'],
                'createIfMissing' => ['title' => 'Admin', 'className' => 'BlockPageStub'],
            ],
            'elements' => [],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'URLSEGMENT_COLLISION', 409);
        $this->assertNull(SiteTree::get()->filter('URLSegment', 'admin-2')->first());
    }

    /**
     * Finding 6: re-composing an element's external id under a different
     * class is refused, not a second element beside the first.
     */
    public function testChangingAnElementsClassForItsExternalIdIsRefused(): void
    {
        $page = $this->objFromFixture(ApiTestBlockPage::class, 'blockPage');

        $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [['class' => 'ApiTestElement', 'externalId' => 'swap-me', 'fields' => ['Title' => 'One']]],
        ], $this->adminToken);

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [['class' => 'ElementContent', 'externalId' => 'swap-me', 'fields' => ['Title' => 'Two']]],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ALREADY_EXISTS', 409);
        $this->assertSame(1, ApiTestElement::get()->filter('FixtureIdentifier', 'swap-me')->count());
        $this->assertSame(
            0,
            \DNADesign\Elemental\Models\ElementContent::get()->filter('FixtureIdentifier', 'swap-me')->count()
        );
    }
}
