<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestElementItem;
use DNADesign\Elemental\Models\ElementContent;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;

class CompositionTest extends ContentApiTestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
        . 'z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('ContentApiCompositionTest');

        $this->adminToken = $this->mintTokenFor('adminUser');

        Config::modify()->set(SiteTree::class, 'api_access', 'read,update');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }

    /**
     * The canonical composition payload used across tests.
     */
    private function payload(ApiTestBlockPage $page): array
    {
        return [
            'page' => [
                'match' => ['id' => (int) $page->ID],
                'fields' => ['MetaDescription' => 'Composed via API'],
            ],
            'publish' => 'recursive',
            'assets' => [
                [
                    'ref' => 'img',
                    'externalId' => 'comp-img',
                    'folder' => 'comp',
                    'filename' => 'pixel.png',
                    'base64' => CompositionTest::PNG_BASE64,
                ],
            ],
            'elements' => [
                [
                    'ref' => 'e1',
                    'class' => 'ElementContent',
                    'externalId' => 'comp-e1',
                    'fields' => ['Title' => 'Intro', 'HTML' => '<p>Hello</p>'],
                ],
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'comp-e2',
                    'fields' => [
                        'Title' => 'With children',
                        'Photo' => ['$ref' => 'img'],
                        'Cta' => ['type' => 'ExternalLink', 'url' => '/contact', 'title' => 'Contact us'],
                    ],
                    'children' => [
                        'Items' => [
                            ['externalId' => 'comp-e2-i1', 'fields' => ['Title' => 'Item 1', 'SortOrder' => 1]],
                            ['externalId' => 'comp-e2-i2', 'fields' => ['Title' => 'Item 2', 'SortOrder' => 2]],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function blockPage(): ApiTestBlockPage
    {
        return $this->objFromFixture(ApiTestBlockPage::class, 'blockPage');
    }

    public function testFullComposition(): void
    {
        $page = $this->blockPage();

        $response = $this->apiPost('compositions/page', $this->payload($page), $this->adminToken);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($body['error']);

        // Page updated sparsely + area attached. (ElementalPageExtension
        // auto-creates the area on page write, so created is false here;
        // the service's own creation path is a stale-FK fallback.)
        $this->assertSame('updated', $body['data']['page']['operation']);
        $this->assertGreaterThan(0, (int) $body['data']['area']['id']);

        $fresh = ApiTestBlockPage::get()->byID($page->ID);
        $this->assertSame('Composed via API', $fresh->MetaDescription);
        $this->assertSame('Block Page', $fresh->Title, 'unsent fields untouched');
        $this->assertSame((int) $body['data']['area']['id'], (int) $fresh->ElementalAreaID);

        // Elements created in order with Sort 1, 2.
        $elements = $body['data']['elements'];
        $this->assertCount(2, $elements);
        $this->assertSame(['created', 'created'], array_column($elements, 'status'));

        $e1 = ElementContent::get()->byID($elements[0]['id']);
        $this->assertSame(1, (int) $e1->Sort);
        $this->assertSame('<p>Hello</p>', $e1->HTML);
        $this->assertTrue($e1->isPublished(), 'publish: recursive publishes elements');

        // $ref image wired into the has_one, asset ingested.
        $e2 = ApiTestElement::get()->byID($elements[1]['id']);
        $this->assertSame(2, (int) $e2->Sort);
        $this->assertSame((int) $body['data']['assets'][0]['id'], (int) $e2->PhotoID);

        // Structured link payload became a written ExternalLink.
        $link = $e2->Cta();
        $this->assertTrue($link->exists());
        $this->assertSame('/contact', $link->ExternalUrl);
        $this->assertSame('Contact us', $link->LinkText);

        // Children upserted, attached and published.
        $this->assertSame(2, $e2->Items()->count());
        $this->assertCount(2, $elements[1]['children']);
        $this->assertTrue($e2->Items()->first()->isPublished());

        // Page live.
        $this->assertTrue($fresh->isPublished());
    }

    public function testCompositionIsIdempotent(): void
    {
        $page = $this->blockPage();
        $payload = $this->payload($page);

        $this->apiPost('compositions/page', $payload, $this->adminToken);
        $second = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));

        $this->assertNull($second['error']);
        $this->assertSame(
            ['updated', 'updated'],
            array_column($second['data']['elements'], 'status'),
            'second run updates, never duplicates'
        );
        $this->assertSame('updated', $second['data']['assets'][0]['status']);
        $this->assertFalse($second['data']['area']['created']);

        // Link updated in place, not re-created.
        $this->assertSame(
            1,
            \SilverStripe\LinkField\Models\ExternalLink::get()->filter('ExternalUrl', '/contact')->count(),
            'idempotent link handling'
        );

        $area = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea();
        $this->assertSame(2, $area->Elements()->count(), 'no duplicate element generations');
        $this->assertSame(
            2,
            ApiTestElement::get()->filter('FixtureIdentifier', 'comp-e2')->first()->Items()->count(),
            'no duplicate children'
        );
    }

    public function testSecondRunUpdatesSparsely(): void
    {
        $page = $this->blockPage();
        $this->apiPost('compositions/page', $this->payload($page), $this->adminToken);

        $sparse = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ElementContent',
                    'externalId' => 'comp-e1',
                    'fields' => ['Title' => 'Intro v2'],
                ],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $sparse, $this->adminToken));
        $this->assertNull($body['error']);

        $e1 = ElementContent::get()->filter('FixtureIdentifier', 'comp-e1')->first();
        $this->assertSame('Intro v2', $e1->Title);
        $this->assertSame('<p>Hello</p>', $e1->HTML, 'unsent HTML survives');
    }

    public function testPruneManagedScope(): void
    {
        $page = $this->blockPage();
        $this->apiPost('compositions/page', $this->payload($page), $this->adminToken);

        // Hand-authored element (no externalId) — must be invisible to prune.
        $area = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea();
        $manual = ElementContent::create();
        $manual->Title = 'Hand made';
        $manual->ParentID = $area->ID;
        $manual->write();

        $pruning = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'prune' => ['enabled' => true, 'scope' => 'managed'],
            'elements' => [
                ['class' => 'ElementContent', 'externalId' => 'comp-e1', 'fields' => []],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $pruning, $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame(['comp-e2'], array_column($body['data']['pruned'], 'externalId'));

        $this->assertNull(ApiTestElement::get()->filter('FixtureIdentifier', 'comp-e2')->first());
        $this->assertNotNull(ElementContent::get()->byID($manual->ID), 'hand-authored content untouched');
    }

    public function testPruneAllScope(): void
    {
        $page = $this->blockPage();
        $this->apiPost('compositions/page', $this->payload($page), $this->adminToken);

        $area = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea();
        $manual = ElementContent::create();
        $manual->Title = 'Hand made';
        $manual->ParentID = $area->ID;
        $manual->write();

        $pruning = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'prune' => ['enabled' => true, 'scope' => 'all'],
            'elements' => [
                ['class' => 'ElementContent', 'externalId' => 'comp-e1', 'fields' => []],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $pruning, $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertNull(ElementContent::get()->byID($manual->ID), 'scope all archives unmanaged too');
    }

    public function testCreateIfMissing(): void
    {
        $payload = [
            'page' => [
                'match' => ['urlSegment' => 'net-new'],
                'createIfMissing' => ['title' => 'Net New', 'parentId' => 0, 'className' => 'BlockPageStub'],
            ],
            'elements' => [
                ['class' => 'ElementContent', 'externalId' => 'nn-e1', 'fields' => ['Title' => 'Fresh']],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame('created', $body['data']['page']['operation']);

        $page = SiteTree::get()->filter('URLSegment', 'net-new')->first();
        $this->assertNotNull($page);
        $this->assertSame(ApiTestBlockPage::class, $page->ClassName);
        $this->assertGreaterThan(0, (int) $page->ElementalAreaID);
    }

    public function testMissingPageWithoutCreateIfMissingIs404(): void
    {
        $payload = ['page' => ['match' => ['urlSegment' => 'nope']], 'elements' => []];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testConvertTo(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $payload = [
            'page' => [
                'match' => ['id' => (int) $about->ID],
                'convertTo' => 'BlockPageStub',
            ],
            'elements' => [
                ['class' => 'ElementContent', 'externalId' => 'conv-e1', 'fields' => ['Title' => 'Converted']],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame('converted', $body['data']['page']['operation']);
        $this->assertSame(ApiTestBlockPage::class, SiteTree::get()->byID($about->ID)->ClassName);
    }

    public function testElementRequiresExternalId(): void
    {
        $payload = [
            'page' => ['match' => ['id' => (int) $this->blockPage()->ID]],
            'elements' => [
                ['class' => 'ElementContent', 'fields' => ['Title' => 'No id']],
            ],
        ];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testCompositionRejectsSinglePublishMode(): void
    {
        $payload = $this->payload($this->blockPage());
        $payload['publish'] = 'single';

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testUnresolvedRefRollsBack(): void
    {
        $payload = [
            'page' => ['match' => ['id' => (int) $this->blockPage()->ID]],
            'elements' => [
                [
                    'class' => 'ElementContent',
                    'externalId' => 'rb-e1',
                    'fields' => ['Title' => 'Will roll back'],
                ],
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'rb-e2',
                    'fields' => ['Photo' => ['$ref' => 'ghost']],
                ],
            ],
        ];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);
        $body = $this->assertErrorCode($response, 'UNRESOLVED_REF', 422);

        $this->assertStringContainsString('rolled back', $body['error']['message']);
        $this->assertNull(
            ElementContent::get()->filter('FixtureIdentifier', 'rb-e1')->first(),
            'earlier element in the failed composition must not persist'
        );
    }

    public function testCompositionRequiresPopulatePermission(): void
    {
        $response = $this->apiPost(
            'compositions/page',
            $this->payload($this->blockPage()),
            $this->mintTokenFor('apiUser')
        );

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testCompositionIsEnvironmentGated(): void
    {
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);

        $response = $this->apiPost('compositions/page', $this->payload($this->blockPage()), $this->adminToken);

        $this->assertErrorCode($response, 'ENV_FORBIDDEN', 403);
    }
}
