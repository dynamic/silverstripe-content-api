<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestElementItem;
use Dynamic\ContentApi\Tests\Stub\ApiTestLink;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestPlainChildObject;
use Dynamic\ContentApi\Write\Transformers\LinkTransformer;
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

        // See tearDown() — reset on both sides of the test so a prior
        // test's leftover cache entry (this cache is keyed only by page
        // class name, regardless of which test file touched it) can never
        // leak in, not just so this test doesn't leak one out.
        static::resetElementalTypesCache();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        // ElementalAreasExtension::getElementalTypes() caches its result per
        // page class name in a static — Config::modify()'s rollback at the
        // end of the test doesn't touch it, so a disallowed_elements test
        // that doesn't reset this here would leak a stale (or fresh but
        // now-wrong) allow-list into whichever test runs next.
        static::resetElementalTypesCache();

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

    /**
     * #130: dryRun is a `POST batch` feature only — rejected outright on
     * compositions rather than silently ignored, same convention as
     * #102's dryRun/liveOnly rejection on non-subtree publish modes.
     */
    public function testDryRunIsRejectedNotSilentlyIgnored(): void
    {
        $page = $this->blockPage();

        $response = $this->apiPost(
            'compositions/page',
            ['dryRun' => true] + $this->payload($page),
            $this->adminToken
        );

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
        $this->assertSame('Block Page', ApiTestBlockPage::get()->byID($page->ID)->Title, 'nothing should have run');
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

    /**
     * #192: `areaRelation` at the request's top level (the shape the MCP
     * tool schema itself documents) used to be silently ignored —
     * `compose()` only ever read it from `page.areaRelation`, so a
     * top-level request composed against the DEFAULT `ElementalArea`
     * instead of the area actually requested, with no error and no
     * warning. Proven against real DB state, on a page with two distinct
     * ElementalArea-typed has_ones (the exact shape that surfaced this
     * live: a HomePage-style class with both a generic default area and
     * its real, differently-named one) — the element must land in
     * `SecondaryArea`, not `ElementalArea`.
     */
    public function testTopLevelAreaRelationIsHonoredNotSilentlyIgnored(): void
    {
        $page = $this->blockPage();

        $payload = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'areaRelation' => 'SecondaryArea',
            'publish' => 'none',
            'elements' => [
                ['class' => 'ElementContent', 'externalId' => 'top-level-area-e1', 'fields' => ['Title' => 'X']],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));

        $this->assertNull($body['error'], (string) json_encode($body));

        $fresh = ApiTestBlockPage::get()->byID($page->ID);
        $this->assertGreaterThan(0, (int) $fresh->SecondaryAreaID, 'SecondaryArea must have been created/attached');
        $this->assertSame(
            (int) $fresh->SecondaryAreaID,
            (int) $body['data']['area']['id'],
            'the composed area must be the one requested via top-level areaRelation'
        );

        $element = ElementContent::get()->byID($body['data']['elements'][0]['id']);
        $this->assertSame(
            (int) $fresh->SecondaryAreaID,
            (int) $element->ParentID,
            'the element must be attached to SecondaryArea, not the default ElementalArea'
        );
        $this->assertNotSame(
            (int) $fresh->ElementalAreaID,
            (int) $element->ParentID,
            'must not have silently landed in the default area instead'
        );
    }

    /**
     * When both locations disagree, `page.areaRelation` wins (it is the
     * shape the backend has always actually read) — but the disagreement
     * itself must be visible in the response, not resolved silently.
     */
    public function testConflictingAreaRelationValuesAreReportedAsAWarning(): void
    {
        $page = $this->blockPage();

        $payload = [
            'page' => [
                'match' => ['id' => (int) $page->ID],
                'areaRelation' => 'SecondaryArea',
            ],
            'areaRelation' => 'ElementalArea',
            'publish' => 'none',
            'elements' => [],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $payload, $this->adminToken));

        $this->assertNull($body['error']);

        $fresh = ApiTestBlockPage::get()->byID($page->ID);
        $this->assertSame(
            (int) $fresh->SecondaryAreaID,
            (int) $body['data']['area']['id'],
            'page.areaRelation wins on conflict'
        );

        $warnings = $body['data']['page']['warnings'] ?? [];
        $this->assertNotEmpty($warnings, 'the conflict must be surfaced, not resolved silently');
        $this->assertSame('areaRelation', $warnings[0]['field']);
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

    public function testPublishRecursiveDoesNotCrashOnNonVersionedChild(): void
    {
        // #37: a has_many child that isn't Versioned (the shape of the real
        // Dynamic\Elements\StatCounters\Model\StatCounter — a plain
        // DataObject with no publishSingle()) must not 500 the whole
        // composition when publish:recursive is requested.
        $payload = [
            'page' => ['match' => ['id' => (int) $this->blockPage()->ID]],
            'publish' => 'recursive',
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'plain-child-e1',
                    'fields' => ['Title' => 'Has a non-versioned child'],
                    'children' => [
                        'PlainItems' => [
                            [
                                'externalId' => 'plain-child-i1',
                                'fields' => ['Title' => 'Plain child', 'SortOrder' => 1],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($body['error']);

        $element = ApiTestElement::get()->filter('FixtureIdentifier', 'plain-child-e1')->first();
        $this->assertNotNull($element);
        $this->assertTrue($element->isPublished(), 'the versioned parent element still publishes');
        $this->assertSame(1, $element->PlainItems()->count());
        $this->assertSame(
            'Plain child',
            ApiTestPlainChildObject::get()->filter('FixtureIdentifier', 'plain-child-i1')->first()->Title
        );
    }

    /**
     * #114: `compose()`'s own `publishAll()` publishes the page directly
     * (`$page->publishRecursive()`), bypassing both `RecordWriter::write()`
     * (the page's own field write here never carries a "publish" key) and
     * `PublishOrchestrator::publish()`'s own authorization (it never checks
     * the root at all) — reachable with no `page.convertTo` in the payload,
     * unlike the `convertPage()` half of #114. A class granting `update`
     * but withholding `action` must refuse this the same way a payload
     * write with a "publish" key does.
     */
    public function testComposeWithPublishRecursiveRequiresThePageClassActionVerb(): void
    {
        Config::modify()->set(ApiTestBlockPage::class, 'api_access', 'read,update');

        // A fresh, unpublished page rather than the shared `blockPage`
        // fixture — that fixture's live-stage row can carry over from an
        // earlier test in this file that legitimately published it, which
        // would make the "nothing should have published" assertion below
        // meaningless regardless of whether this fix actually works.
        $page = ApiTestBlockPage::create(['Title' => 'Fresh Unpublished Block Page']);
        $page->write();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'publish' => 'recursive',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
        $this->assertFalse(
            ApiTestBlockPage::get()->byID($page->ID)->isPublished(),
            'nothing should have published — the check must run before publishAll()'
        );
    }

    /**
     * #119/#168: `publishAll()` now routes the area/element/child cascade
     * through `PublishOrchestrator::publishOwnedTree()`, which
     * authorization-checks every one of them (class `action` verb) before
     * writing anything — previously `publish($record, 'single', $member)`
     * performed no authorization at all for these classes. A class granting
     * `create`/`update` but withholding `action` must refuse the whole
     * composition publish, the same way #114 already does for the page
     * itself in the test above.
     */
    public function testComposeWithPublishRecursiveRequiresEveryElementClassActionVerb(): void
    {
        Config::modify()->set(ApiTestElement::class, 'api_access', 'read,create,update');

        $page = ApiTestBlockPage::create(['Title' => 'Owns Cascade Auth Gap Page']);
        $page->write();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'publish' => 'recursive',
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'owns-auth-e1',
                    'fields' => ['Title' => 'Gated Element'],
                ],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);

        // compose() runs the whole request inside one DbTransaction — a
        // FORBIDDEN_CLASS raised mid-publishAll() rolls back the entire
        // composition, not just the publish step: the element's own
        // create() never persists either. Unlike RecordWriter::write()'s
        // single-record #114 check (see RecordWriterTest), this is a
        // transactional gate, not a publish-only one.
        $this->assertFalse(
            ApiTestBlockPage::get()->byID($page->ID)->isPublished(),
            'the whole cascade is authorization-checked before any write — the page must not publish either'
        );
        $this->assertNull(
            ApiTestElement::get()->filter('FixtureIdentifier', 'owns-auth-e1')->first(),
            'the whole composition rolls back, including the element write itself'
        );
    }

    /**
     * #119/#168, the case the test above can't cover: `ApiTestElement`'s own
     * children (`ApiTestElementItem`) are only reachable via
     * `publishAll()`'s `$additional` list — `BaseElement` declares no
     * `$owns`, so `PublishOrchestrator::publishOwnedTree()`'s walk never
     * finds them on its own. A regression that dropped `$additional`
     * entries from the authorization-check loop would pass the test above
     * (the element itself is walk-reachable through the area) but not this
     * one.
     */
    public function testComposeWithPublishRecursiveRequiresElementChildClassActionVerb(): void
    {
        Config::modify()->set(ApiTestElementItem::class, 'api_access', 'read,create,update');

        $page = ApiTestBlockPage::create(['Title' => 'Owns Cascade Child Auth Gap Page']);
        $page->write();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'publish' => 'recursive',
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'owns-auth-child-e1',
                    'fields' => ['Title' => 'Parent With Gated Child'],
                    'children' => [
                        'Items' => [
                            ['externalId' => 'owns-auth-child-i1', 'fields' => ['Title' => 'Gated Child']],
                        ],
                    ],
                ],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);

        $this->assertFalse(
            ApiTestBlockPage::get()->byID($page->ID)->isPublished(),
            'the whole cascade is authorization-checked before any write, including additional-only targets'
        );
        $this->assertNull(
            ApiTestElement::get()->filter('FixtureIdentifier', 'owns-auth-child-e1')->first(),
            'the whole composition rolls back'
        );
        $this->assertNull(
            ApiTestElementItem::get()->filter('FixtureIdentifier', 'owns-auth-child-i1')->first()
        );
    }

    /**
     * The positive-space/regression half: a page write with no "publish"
     * key at all (mode defaults to "none" at the composition level) must
     * stay reachable on "update" alone, matching every other #114 gate's
     * no-op for "none".
     */
    public function testComposeWithoutAPublishModeDoesNotRequireThePageClassActionVerb(): void
    {
        Config::modify()->set(ApiTestBlockPage::class, 'api_access', 'read,update');

        $page = ApiTestBlockPage::create(['Title' => 'Fresh No-Publish-Key Block Page']);
        $page->write();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
        ], $this->adminToken);

        $this->assertNull($this->decode($response)['error']);
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

    public function testBareAllowlistIsEnforcedOnElementWrites(): void
    {
        // Regression for #15 on the compositions surface: CompositionService's
        // element/child writes route through the same WriteApplicator as
        // batch (BatchTest::testBareAllowlistIsEnforcedOnTrustedPath covers
        // that surface), but had no direct assertion here that a bare
        // api_writable_fields (no explicit api_write_policy) is still
        // enforced on this surface too, not just implied by shared code.
        Config::modify()->set(ElementContent::class, 'api_writable_fields', ['Title']);

        $payload = [
            'page' => ['match' => ['id' => (int) $this->blockPage()->ID]],
            'elements' => [
                [
                    'class' => 'ElementContent',
                    'externalId' => 'bare-allowlist-e1',
                    'fields' => ['Title' => 'Allowed', 'HTML' => 'Not in the allowlist'],
                ],
            ],
        ];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);

        $this->assertErrorCode($response, 'READONLY_FIELD', 422);
        $this->assertNull(
            ElementContent::get()->filter('FixtureIdentifier', 'bare-allowlist-e1')->first(),
            'rejected write must not leave a partial record behind'
        );
    }

    public function testParentIdIsWritableWithoutBeingAllowlisted(): void
    {
        // Module #27 payoff: ParentID is server-derived (WriteApplicator's
        // trusted $internalFields channel), so a class's api_writable_fields
        // no longer needs to list it for a composition to attach elements.
        // See WriteGuardTest::testParentIdStaysReadonlyOnColymbaSurface for
        // the matching security-half assertion — it's still rejected on the
        // untrusted public PUT surface.
        Config::modify()->set(ElementContent::class, 'api_writable_fields', ['Title']);

        $page = $this->blockPage();
        $payload = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ElementContent',
                    'externalId' => 'parentid-e1',
                    'fields' => ['Title' => 'Attached without ParentID allowlisted'],
                ],
            ],
        ];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($body['error']);

        $area = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea();
        $element = ElementContent::get()->filter('FixtureIdentifier', 'parentid-e1')->first();

        $this->assertNotNull($element);
        $this->assertSame((int) $area->ID, (int) $element->ParentID);
    }

    public function testChildWriteRequiresClassAccess(): void
    {
        // Module #19: CONTENT_API_POPULATE alone is not sufficient to write
        // an arbitrary has_many-target class — the child class must also
        // grant api_access for the verb, mirroring every other write surface
        // (batch, single-record, colymba).
        Config::modify()->set(ApiTestElementItem::class, 'api_access', 'read');

        $payload = [
            'page' => ['match' => ['id' => (int) $this->blockPage()->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'acl-e1',
                    'fields' => ['Title' => 'Parent'],
                    'children' => [
                        'Items' => [
                            ['externalId' => 'acl-e1-i1', 'fields' => ['Title' => 'Denied child']],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->apiPost('compositions/page', $payload, $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
        $this->assertNull(
            ApiTestElementItem::get()->filter('FixtureIdentifier', 'acl-e1-i1')->first(),
            'denied child write must not persist'
        );
        $this->assertNull(
            ApiTestElement::get()->filter('FixtureIdentifier', 'acl-e1')->first(),
            'the whole composition rolls back, including the parent element'
        );
    }

    public function testChildLookupIsScopedToOwningElement(): void
    {
        // Module #19: a composition child externalId must not adopt/
        // re-parent a child record that already belongs to a DIFFERENT
        // element — ExternalIdResolver::tryFind() is a global lookup by
        // design, so the composition path scopes the search itself.
        $page = $this->blockPage();

        $first = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'scope-e1',
                    'fields' => ['Title' => 'First'],
                    'children' => [
                        'Items' => [
                            ['externalId' => 'shared-child', 'fields' => ['Title' => 'Owned by e1']],
                        ],
                    ],
                ],
            ],
        ];

        $this->apiPost('compositions/page', $first, $this->adminToken);

        $second = [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'scope-e2',
                    'fields' => ['Title' => 'Second'],
                    'children' => [
                        'Items' => [
                            ['externalId' => 'shared-child', 'fields' => ['Title' => 'Owned by e2']],
                        ],
                    ],
                ],
            ],
        ];

        $body = $this->decode($this->apiPost('compositions/page', $second, $this->adminToken));
        $this->assertNull($body['error']);

        $e1 = ApiTestElement::get()->filter('FixtureIdentifier', 'scope-e1')->first();
        $e2 = ApiTestElement::get()->filter('FixtureIdentifier', 'scope-e2')->first();

        $this->assertSame(1, $e1->Items()->count(), 'e1 keeps its own child');
        $this->assertSame('Owned by e1', $e1->Items()->first()->Title, 'e1 child untouched');

        $this->assertSame(1, $e2->Items()->count(), "e2 gets its own new child, not e1's");
        $this->assertSame('Owned by e2', $e2->Items()->first()->Title);
        $this->assertNotSame(
            (int) $e1->Items()->first()->ID,
            (int) $e2->Items()->first()->ID,
            "e2 must not adopt e1's child"
        );
    }

    public function testPageCreationValidationFailureDoesNotLeakRawExceptionText(): void
    {
        Config::modify()->set(ApiTestPage::class, 'api_access', true);

        $response = $this->apiPost('compositions/page', [
            'page' => [
                'match' => ['urlSegment' => 'brand-new-page'],
                'createIfMissing' => ['title' => 'Invalid', 'className' => 'ApiTestPage'],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        // A fixed, generic summary — never the raw exception text (#21).
        $this->assertSame('1 field(s) failed validation. (composition rolled back)', $body['error']['message']);
        $this->assertNotEmpty($body['error']['details']);
        $this->assertSame('Title', $body['error']['details'][0]['field']);
    }

    public function testChildValidationFailureDoesNotLeakRawExceptionText(): void
    {
        $page = $this->blockPage();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'invalid-child-parent',
                    'fields' => ['Title' => 'Parent'],
                    'children' => [
                        'Items' => [
                            ['externalId' => 'invalid-child', 'fields' => ['Title' => 'Invalid']],
                        ],
                    ],
                ],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        // Prefixed with which child failed, but no raw exception text (#21).
        $this->assertSame(
            'Child "invalid-child": 1 field(s) failed validation. (composition rolled back)',
            $body['error']['message']
        );
        $this->assertSame('Title', $body['error']['details'][0]['field']);
    }

    public function testLinkValidationFailureDoesNotLeakRawExceptionText(): void
    {
        Config::modify()->merge(LinkTransformer::class, 'type_map', ['TestLink' => ApiTestLink::class]);

        $page = $this->blockPage();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'invalid-link-owner',
                    'fields' => [
                        'Title' => 'Owner',
                        'Cta' => ['type' => 'TestLink', 'title' => 'Invalid'],
                    ],
                ],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        // Prefixed with which field's link failed, but no raw exception
        // text (#21).
        $this->assertSame(
            'Link for "Cta": 1 field(s) failed validation. (composition rolled back)',
            $body['error']['message']
        );
        $this->assertSame('LinkText', $body['error']['details'][0]['field']);
    }

    /**
     * #195: a link payload key not in LinkTransformer's FIELD_MAP used to
     * be silently ignored (`fileID`/`file`/`target` instead of the real
     * key `fileId`, for example) — the link record was still created, just
     * empty, with a 200 response and nothing to say the key never matched
     * anything. Proven against real DB state, not just the error code: no
     * link record must be left behind by the rejected write.
     */
    public function testLinkPayloadWithAnUnknownKeyIsRejectedNotSilentlyDropped(): void
    {
        $page = $this->blockPage();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'unknown-link-key-owner',
                    'fields' => [
                        'Title' => 'Owner',
                        // "urll" is a plausible typo for the real key "url"
                        // — FIELD_MAP has no entry for it, so this used to
                        // write an ExternalLink with no ExternalUrl at all.
                        'Cta' => ['type' => 'ExternalLink', 'urll' => '/contact'],
                    ],
                ],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'UNKNOWN_FIELD', 422);
        $this->assertStringContainsString('urll', $body['error']['message']);

        $owner = ApiTestElement::get()->filter('FixtureIdentifier', 'unknown-link-key-owner')->first();
        $this->assertNull($owner, 'the whole composition must have rolled back, not just the link');
    }

    /**
     * #64: composing an element type the target page type's Elemental
     * config disallows must be rejected — a CMS editor could never create
     * this element through the "add element" picker on this page type, and
     * the API is not a side door around that.
     */
    public function testComposingADisallowedElementIsRejected(): void
    {
        $page = $this->blockPage();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'disallowed-1',
                    'fields' => ['Title' => 'Should be rejected'],
                ],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ELEMENT_NOT_ALLOWED_ON_PAGE', 422);
        $this->assertNull(
            ApiTestElement::get()->filter('FixtureIdentifier', 'disallowed-1')->first(),
            'a rejected element must not be persisted'
        );
    }

    /**
     * The enforcement is per page-class-and-element-class, not a global
     * kill switch — disallowing ApiTestElement on this page type must not
     * also block a different, still-allowed element type.
     */
    public function testComposingAnAllowedElementStillSucceedsWhenAnotherTypeIsDisallowed(): void
    {
        $page = $this->blockPage();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ElementContent',
                    'externalId' => 'still-allowed-1',
                    'fields' => ['Title' => 'Still allowed', 'HTML' => '<p>ok</p>'],
                ],
            ],
        ], $this->adminToken);

        $body = $this->decode($response);

        $this->assertNull($body['error']);
        $this->assertSame(['created'], array_column($body['data']['elements'], 'status'));
    }

    /**
     * #64 review follow-up: `getElementalTypes()` (the CMS "add element"
     * picker, `moveTo()`) only ever gates a NEW placement — it has no
     * equivalent for "keep editing an element already on the page". A
     * plain re-POST of an already-composed element must keep succeeding
     * even after the page's config narrows to newly disallow that
     * element's type, or this module's own re-POST idempotency guarantee
     * (`schema/endpoints.json`) breaks the moment any page's Elemental
     * config changes.
     */
    public function testEditingAnAlreadyPlacedElementSucceedsEvenAfterItsTypeBecomesDisallowed(): void
    {
        $page = $this->blockPage();

        $create = $this->decode($this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'edit-after-disallow',
                    'fields' => ['Title' => 'Created while allowed'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame(['created'], array_column($create['data']['elements'], 'status'));

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $edit = $this->decode($this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'edit-after-disallow',
                    'fields' => ['Title' => 'Edited after disallow'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($edit['error']);
        $this->assertSame(['updated'], array_column($edit['data']['elements'], 'status'));
        $this->assertSame(
            'Edited after disallow',
            ApiTestElement::get()->filter('FixtureIdentifier', 'edit-after-disallow')->first()->Title
        );
    }
}
