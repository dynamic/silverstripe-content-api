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
                            ['externalId' => 'plain-child-i1', 'fields' => ['Title' => 'Plain child', 'SortOrder' => 1]],
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
     * #115/#118: `ExposureScaffolder` (the `sake tasks:GenerateContentApiExposure`
     * generator) deliberately puts every class it scaffolds into ALLOWLIST
     * mode (a non-empty `api_writable_fields`) and just as deliberately
     * never includes `Parent`/`ParentID` in that list — see its own
     * docblock. `testComposingADisallowedElementIsRejected` above proves
     * placement enforcement under this fixture's *default* `guarded`
     * policy; this proves the same guard still fires once the class is
     * reconfigured into the exact allowlist shape the generator would
     * actually produce, so a future refactor can't accidentally make
     * `RecordWriter::assertElementPlacementAllowed()` conditional on write
     * policy without a test catching it. `ParentID` is deliberately absent
     * from the temporary allowlist below — composition sets it via the
     * trusted internal channel regardless, matching how the generator's
     * own output is meant to be used.
     */
    public function testComposingADisallowedElementIsRejectedUnderAGeneratorShapedAllowlist(): void
    {
        $page = $this->blockPage();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        Config::modify()->set(ApiTestElement::class, 'api_writable_fields', [
            'Title', 'ShowTitle', 'Sort', 'ExtraClass', 'Style', 'Intro', 'Photo', 'Cta',
        ]);
        static::resetElementalTypesCache();

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                [
                    'class' => 'ApiTestElement',
                    'externalId' => 'disallowed-allowlist-1',
                    'fields' => ['Title' => 'Should still be rejected'],
                ],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ELEMENT_NOT_ALLOWED_ON_PAGE', 422);
        $this->assertNull(
            ApiTestElement::get()->filter('FixtureIdentifier', 'disallowed-allowlist-1')->first(),
            'a rejected element must not be persisted, allowlist mode or not'
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
