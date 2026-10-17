<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * #242: a has_one link payload is part of its owner's write. The link is
 * written draft-only inside the operation's transaction, names its owner,
 * and goes live when the owner is published.
 */
class LinkLifecycleTest extends ContentApiTestCase
{
    private const LINK = 'SilverStripe\\LinkField\\Models\\Link';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(LinkLifecycleTest::LINK)) {
            $this->markTestSkipped('silverstripe/linkfield is not installed.');
        }

        TestAssetStore::activate('ContentApiLinkLifecycleTest');
        $this->adminToken = $this->mintTokenFor('adminUser');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }

    private function area(): ElementalArea
    {
        return Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);

            $page = ApiTestBlockPage::create(['Title' => 'Link host']);
            $page->write();

            return $page->ElementalArea();
        });
    }

    private function batch(array $operation): array
    {
        $body = $this->decode($this->apiPost('batch', ['operations' => [$operation]], $this->adminToken));

        return $body['data']['results'][0];
    }

    private function createElement(string $url, string $publish = 'single'): ApiTestElement
    {
        $result = $this->batch([
            'op' => 'create',
            'class' => 'ApiTestElement',
            'fields' => [
                'Title' => 'Card',
                'ParentID' => (int) $this->area()->ID,
                'Cta' => ['type' => 'ExternalLink', 'url' => $url, 'title' => 'Go'],
            ],
            'publish' => $publish,
        ]);
        $this->assertSame('created', $result['status'], json_encode($result));

        return ApiTestElement::get()->byID($result['id']);
    }

    private function updateCta(ApiTestElement $element, array $cta, string $publish): array
    {
        return $this->batch([
            'op' => 'update',
            'class' => 'ApiTestElement',
            'id' => (int) $element->ID,
            'fields' => ['Cta' => $cta],
            'publish' => $publish,
        ]);
    }

    private function onStage(string $stage, int $linkId): ?DataObject
    {
        return Versioned::get_by_stage(LinkLifecycleTest::LINK, $stage)->byID($linkId);
    }

    /**
     * Finding 1: a `publish: "none"` edit stays on draft until the owner
     * publishes.
     */
    public function testADraftOnlyLinkEditWaitsForTheOwnerPublish(): void
    {
        $element = $this->createElement('/before');
        $linkId = (int) $element->CtaID;
        $this->assertSame('/before', $this->onStage(Versioned::LIVE, $linkId)->ExternalUrl);

        $result = $this->updateCta($element, ['type' => 'ExternalLink', 'url' => '/after', 'title' => 'Go'], 'none');
        $this->assertSame('updated', $result['status'], json_encode($result));

        $this->assertSame('/before', $this->onStage(Versioned::LIVE, $linkId)->ExternalUrl);
        $this->assertSame('/after', $this->onStage(Versioned::DRAFT, $linkId)->ExternalUrl);

        $publish = $this->batch([
            'op' => 'update',
            'class' => 'ApiTestElement',
            'id' => (int) $element->ID,
            'fields' => ['Title' => 'Card'],
            'publish' => 'single',
        ]);
        $this->assertSame('updated', $publish['status'], json_encode($publish));
        $this->assertSame('/after', $this->onStage(Versioned::LIVE, $linkId)->ExternalUrl);
    }

    /**
     * Finding 1: publishing a link takes the owner's `action` verb.
     */
    public function testALinkPublishNeedsTheOwnerActionVerb(): void
    {
        $element = $this->createElement('/before');
        $linkId = (int) $element->CtaID;
        Config::modify()->set(ApiTestElement::class, 'api_access', 'read,create,update');

        $result = $this->updateCta($element, ['type' => 'ExternalLink', 'url' => '/after'], 'single');

        $this->assertSame('error', $result['status']);
        $this->assertSame('FORBIDDEN_CLASS', $result['error']['code']);
        $this->assertSame('/before', $this->onStage(Versioned::LIVE, $linkId)->ExternalUrl);
    }

    /**
     * Finding 2: links name their owner, on create and on update, so an
     * editor who can edit the owner can edit the link.
     */
    public function testLinksNameTheirOwner(): void
    {
        $element = $this->createElement('/contact', 'none');
        $link = DataObject::get_by_id(LinkLifecycleTest::LINK, (int) $element->CtaID);

        $this->assertSame((int) $element->ID, (int) $link->OwnerID);
        $this->assertSame(ApiTestElement::class, $link->OwnerClass);
        $this->assertSame('Cta', $link->OwnerRelation);

        $this->updateCta($element, ['type' => 'ExternalLink', 'url' => '/again'], 'none');
        $link = DataObject::get_by_id(LinkLifecycleTest::LINK, (int) $element->CtaID);
        $this->assertSame((int) $element->ID, (int) $link->OwnerID);

        $editor = $this->cmsEditor();
        $this->assertTrue((bool) $element->canEdit($editor), 'fixture: the editor can edit the owner');
        $this->assertTrue((bool) $link->canEdit($editor));
    }

    /**
     * Finding 3: a non-atomic batch op that fails after field application
     * leaves no link behind.
     */
    public function testAFailedOperationLeavesNoLink(): void
    {
        $area = $this->area();
        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();
        $before = DataObject::get(LinkLifecycleTest::LINK)->count();

        $result = $this->batch([
            'op' => 'create',
            'class' => 'ApiTestElement',
            'fields' => [
                'Title' => 'Rejected',
                'ParentID' => (int) $area->ID,
                'Cta' => ['type' => 'ExternalLink', 'url' => '/orphan-probe'],
            ],
        ]);

        $this->assertSame('ELEMENT_NOT_ALLOWED_ON_PAGE', $result['error']['code']);
        $this->assertSame($before, DataObject::get(LinkLifecycleTest::LINK)->count());
        $this->assertSame(0, Versioned::get_by_stage('SilverStripe\\LinkField\\Models\\ExternalLink', Versioned::LIVE)
            ->filter('ExternalUrl', '/orphan-probe')->count());
    }

    /**
     * Finding 3: a type change archives the replaced link when the owner
     * publishes, not before (the live owner still points at it until then).
     */
    public function testATypeChangeRetiresTheOldLinkOnPublish(): void
    {
        $element = $this->createElement('/before');
        $oldId = (int) $element->CtaID;

        $this->updateCta($element, ['type' => 'PhoneLink', 'phone' => '555-0100'], 'none');
        $this->assertNotNull($this->onStage(Versioned::LIVE, $oldId), 'still what the live owner points at');

        $result = $this->updateCta($element, ['type' => 'PhoneLink', 'phone' => '555-0100'], 'single');
        $this->assertSame('updated', $result['status'], json_encode($result));

        $newId = (int) ApiTestElement::get()->byID($element->ID)->CtaID;
        $this->assertNotSame($oldId, $newId);
        $this->assertNull($this->onStage(Versioned::LIVE, $oldId));
        $this->assertNull($this->onStage(Versioned::DRAFT, $oldId));
        $this->assertSame('555-0100', $this->onStage(Versioned::LIVE, $newId)->Phone);
    }

    /**
     * A composition publishes element links with the elements under
     * `recursive`, and leaves them on draft under `none`.
     */
    public function testCompositionPublishesElementLinksOnlyWhenItPublishes(): void
    {
        $page = $this->objFromFixture(ApiTestBlockPage::class, 'blockPage');
        $compose = fn (string $publish, string $url) => $this->apiPost('compositions/page', [
            'page' => ['match' => ['id' => (int) $page->ID]],
            'publish' => $publish,
            'elements' => [[
                'class' => 'ApiTestElement',
                'externalId' => 'linked',
                'fields' => ['Title' => 'Linked', 'Cta' => ['type' => 'ExternalLink', 'url' => $url]],
            ]],
        ], $this->adminToken);

        $response = $compose('none', '/draft');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $linkId = (int) ApiTestElement::get()->filter('FixtureIdentifier', 'linked')->first()->CtaID;
        $this->assertNull($this->onStage(Versioned::LIVE, $linkId));

        $response = $compose('recursive', '/live');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('/live', $this->onStage(Versioned::LIVE, $linkId)->ExternalUrl);
        $this->assertContains($linkId, array_column($this->decode($response)['data']['published'], 'id'));
    }

    private function cmsEditor(): Member
    {
        $group = Group::create(['Title' => 'Link editors']);
        $group->write();

        foreach (['CMS_ACCESS_CMSMain', 'SITETREE_EDIT_ALL', 'SITETREE_VIEW_ALL'] as $code) {
            Permission::grant($group->ID, $code);
        }

        $member = Member::create(['Email' => 'link-editor@example.com']);
        $member->write();
        $member->Groups()->add($group);

        return $member;
    }
}
