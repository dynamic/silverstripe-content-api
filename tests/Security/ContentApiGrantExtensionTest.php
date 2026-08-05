<?php

namespace Dynamic\ContentApi\Tests\Security;

use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantSubPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Covers Security\ContentApiGrantExtension: a CONTENT_API_ACCESS-only Member
 * must get view/edit/create/delete on a class that declares its own
 * content_api_access, must get NOTHING on a subclass that only inherits it,
 * and every other Member's permissions must be unaffected. No fixture file —
 * members/groups are built imperatively (matches
 * ServiceAccountProvisionerTest's convention); real DB writes, so
 * $usesDatabase = true (see #72: a missing one polluted the real dev DB).
 */
class ContentApiGrantExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        ApiTestPage::class => [ContentApiGrantExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ApiTestPage::class, 'content_api_access', 'GET,POST,PUT,DELETE,action');
    }

    public function testDeclaringClassGrantsAllFourVerbs(): void
    {
        $page = ApiTestPage::create(['Title' => 'Grant Test Page']);
        $page->write();
        $page->publishRecursive();

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertTrue((bool) $page->canView($member));
        $this->assertTrue((bool) $page->canEdit($member));
        $this->assertTrue((bool) $page->canCreate($member));
        $this->assertTrue((bool) $page->canDelete($member));
    }

    /**
     * The escalation regression this extension exists to close (found on
     * the downstream project's first cut, before it was fixed to check
     * Config::UNINHERITED): ClassRegistry::accessVerbs() — the class-level
     * gate — DOES inherit ApiTestPage's declared verbs for this subclass,
     * on purpose, existing behaviour. Asserting that first is what proves
     * the second block is a real regression guard and not just an
     * accidental pass: despite the class gate inheriting, the record-level
     * grant must answer nothing for a class that never declared itself.
     */
    public function testInheritingSubclassIsNeverGranted(): void
    {
        $registry = ClassRegistry::singleton();
        $this->assertNotSame(
            [],
            $registry->accessVerbs(ApiTestGrantSubPage::class),
            'sanity check: the class-level gate is expected to inherit here'
        );
        $this->assertSame(
            [],
            $registry->ownAccessVerbs(ApiTestGrantSubPage::class),
            'the subclass must not appear to declare anything of its own'
        );

        $subPage = ApiTestGrantSubPage::create(['Title' => 'Inheriting Subpage']);
        $subPage->write();

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS, 'VIEW_DRAFT_CONTENT']);

        $this->assertFalse((bool) $subPage->canEdit($member));
        $this->assertFalse((bool) $subPage->canCreate($member));
        $this->assertFalse((bool) $subPage->canDelete($member));
    }

    public function testVerbScopingWithholdsDeleteWhenNotDeclared(): void
    {
        Config::modify()->set(ApiTestPage::class, 'content_api_access', 'GET,POST,PUT,action');

        $page = ApiTestPage::create(['Title' => 'No Delete Verb']);
        $page->write();

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertTrue((bool) $page->canEdit($member));
        $this->assertFalse((bool) $page->canDelete($member));
    }

    public function testMemberWithoutContentApiAccessIsNotGranted(): void
    {
        $page = ApiTestPage::create(['Title' => 'No Access Member']);
        $page->write();

        $member = $this->memberWithCodes([]);

        $this->assertFalse((bool) $page->canEdit($member));
        $this->assertFalse((bool) $page->canCreate($member));
        $this->assertFalse((bool) $page->canDelete($member));
    }

    /**
     * The never-`false` guard: DataObject::extendedCan() takes the minimum
     * of every extension's answer, so if a future edit ever changed one of
     * the four abstain paths from `return null` to `return false`, an
     * ordinary CMS editor unrelated to the service account would be denied
     * too, silently, with no other test catching it.
     */
    public function testOrdinaryEditorIsUnaffected(): void
    {
        $page = ApiTestPage::create(['Title' => 'Ordinary Editor Page']);
        $page->write();
        $page->publishRecursive();

        $member = $this->memberWithCodes(['SITETREE_EDIT_ALL', 'CMS_ACCESS_CMSMain', 'VIEW_DRAFT_CONTENT']);

        $this->assertTrue((bool) $page->canView($member));
        $this->assertTrue((bool) $page->canEdit($member));
        $this->assertTrue((bool) $page->canCreate($member));
        $this->assertTrue((bool) $page->canDelete($member));
    }

    /**
     * canView() alone does not make a draft-only (never-published) record
     * readable: silverstripe/versioned's own canViewVersioned() independently
     * answers false the moment draft and live diverge, and that false
     * participates in the same extendedCan() minimum, overriding this
     * extension's true. VIEW_DRAFT_CONTENT is what satisfies Versioned's own
     * check — the real service account already holds it alongside
     * CONTENT_API_ACCESS (ServiceAccountProvisioner grants both), so this
     * pairing matches production, not a workaround for the test.
     */
    public function testDraftOnlyReadStillNeedsViewDraftContent(): void
    {
        $page = ApiTestPage::create(['Title' => 'Draft Only Page']);
        $page->write();

        $withoutDraft = $this->memberWithCodes([ContentApiPermissions::ACCESS]);
        $this->assertFalse((bool) $page->canView($withoutDraft));

        $withDraft = $this->memberWithCodes([ContentApiPermissions::ACCESS, 'VIEW_DRAFT_CONTENT']);
        $this->assertTrue((bool) $page->canView($withDraft));
    }

    public function testServiceAccountCannotReachCmsAdmin(): void
    {
        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertFalse(
            Permission::checkMember($member, 'CMS_ACCESS_CMSMain'),
            'a content-api service account must stay API-scoped and never gain a real CMS login'
        );
    }

    private function memberWithCodes(array $codes): Member
    {
        $label = $codes ? implode('-', $codes) : 'none';

        $group = Group::create();
        $group->Title = 'Grant test group ' . $label . ' ' . uniqid();
        $group->write();

        foreach ($codes as $code) {
            Permission::grant((int) $group->ID, $code);
        }

        $member = Member::create();
        $member->Email = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label)) . '-' . uniqid() . '@example.com';
        $member->write();
        $member->Groups()->add($group);

        return $member;
    }
}
