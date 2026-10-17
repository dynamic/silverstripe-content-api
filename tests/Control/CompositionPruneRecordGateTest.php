<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestDeleteDenyingExtension;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use SilverStripe\Assets\Dev\TestAssetStore;

/**
 * #227: prune checks canDelete() on each element, not only the class
 * `delete` verb. A record-level refusal archives nothing.
 */
class CompositionPruneRecordGateTest extends ContentApiTestCase
{
    protected static $required_extensions = [
        ApiTestElement::class => [ApiTestDeleteDenyingExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('ContentApiCompositionPruneRecordGateTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testPruneRefusesAnElementWhoseCanDeleteIsFalse(): void
    {
        $page = $this->objFromFixture(ApiTestBlockPage::class, 'blockPage');
        $token = $this->mintTokenFor('adminUser');

        $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'elements' => [
                ['class' => 'ElementContent', 'externalId' => 'keep-me', 'fields' => ['Title' => 'a']],
                ['class' => 'ApiTestElement', 'externalId' => 'locked', 'fields' => ['Title' => 'b']],
            ],
        ], $token);

        $response = $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'prune' => ['enabled' => true, 'scope' => 'all'],
            'elements' => [],
        ], $token);

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
        $this->assertNotNull(ApiTestElement::get()->filter('FixtureIdentifier', 'locked')->first());
        $this->assertNotNull(
            \DNADesign\Elemental\Models\ElementContent::get()->filter('FixtureIdentifier', 'keep-me')->first(),
            'all candidates are checked before any is archived'
        );
    }
}
