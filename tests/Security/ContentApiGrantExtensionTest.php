<?php

namespace Dynamic\ContentApi\Tests\Security;

use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
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
        ApiTestElement::class => [ContentApiGrantExtension::class],
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

    /**
     * BRANCH DIVERGENCE from branch `1`'s equivalent test
     * (testCanDeleteOnAPublishedRecordAlsoNeedsTheEditGrant): on branch `1`,
     * silverstripe/versioned's Versioned::canDelete() independently vetoes
     * (returns false, participating in the same extendedCan() minimum)
     * archiving an already-published record unless canUnpublish() succeeds —
     * so a class declaring only the delete verb (no update/action) cannot
     * archive a published record there.
     *
     * That veto does not exist on this branch's `silverstripe/versioned`
     * (`2.4.x-dev`, confirmed against a real SS5.2 install): `Versioned.php`
     * has NO canDelete() override at all — only a deprecated canArchive()
     * whose own docblock says "Use canDelete() instead", i.e. canDelete()
     * only starts carrying archive semantics on the version branch `1`
     * depends on. `RecordActionsHandler` gates archive on canDelete()
     * regardless of branch (that file is identical between `1` and `ss5`),
     * so on THIS branch canDelete() answers this extension's own true
     * unchallenged — a class declaring only DELETE genuinely CAN archive an
     * already-published record here. Confirmed live: removing the canEdit
     * grant does not block archive on a real SS5.2 site running this
     * branch's dependency versions.
     *
     * Do not "fix" this by porting the branch-`1` assertion here — it would
     * fail against this branch's real, correct behaviour, not a bug.
     */
    public function testCanDeleteOnAPublishedRecordDoesNotNeedTheEditGrantHere(): void
    {
        Config::modify()->set(ApiTestPage::class, 'content_api_access', 'GET,DELETE');

        $page = ApiTestPage::create(['Title' => 'Delete Only Published']);
        $page->write();
        $page->publishRecursive();

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertTrue(
            (bool) $page->canDelete($member),
            'on this branch, unlike branch 1, silverstripe/versioned does not override '
                . 'canDelete() to veto based on canUnpublish() — see this test\'s docblock'
        );
    }

    /**
     * BaseElement::canView()/canEdit()/canDelete() delegate to getPage() when
     * one exists, but canCreate() does NOT — it falls straight to
     * Permission::check('CMS_ACCESS', 'any', $member), which a
     * CONTENT_API_ACCESS-only account can never satisfy on its own. Applying
     * this extension to SiteTree alone is therefore not enough for a service
     * account to create elements via compositions/batch; it must also be
     * applied to BaseElement (see docs/en/04_security-model.md). This proves
     * the extension itself works identically there — it's a deployment
     * concern (which classes a project's YAML applies it to), not something
     * the extension's own logic needs to special-case.
     */
    public function testGrantsCanCreateOnABaseElementSubclass(): void
    {
        Config::modify()->set(ApiTestElement::class, 'content_api_access', 'GET,POST,PUT,DELETE,action');

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertTrue((bool) ApiTestElement::singleton()->canCreate($member));
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

    public function testVerbScopingWithholdsCreateWhenNotDeclared(): void
    {
        Config::modify()->set(ApiTestPage::class, 'content_api_access', 'GET,PUT,DELETE,action');

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertFalse((bool) ApiTestPage::singleton()->canCreate($member));
    }

    /**
     * A published SiteTree record is publicly viewable by default
     * (CanViewType 'Inherit'/'Anyone'), which would mask this extension
     * correctly abstaining on canView() — so CanViewType is locked down to
     * an unrelated group here specifically to isolate the verb-scoping
     * behaviour from SiteTree's own open-by-default view logic.
     */
    public function testVerbScopingWithholdsViewWhenNotDeclared(): void
    {
        Config::modify()->set(ApiTestPage::class, 'content_api_access', 'POST,PUT,DELETE,action');

        $restrictedGroup = Group::create();
        $restrictedGroup->Title = 'Restricted Viewers ' . uniqid();
        $restrictedGroup->write();

        $page = ApiTestPage::create(['Title' => 'No Read Verb', 'CanViewType' => 'OnlyTheseUsers']);
        $page->write();
        $page->ViewerGroups()->add($restrictedGroup);
        $page->publishRecursive();

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertTrue((bool) $page->canEdit($member));
        $this->assertFalse((bool) $page->canView($member));
    }

    /**
     * The exact deployment gotcha ContentApiGrantExtension's own docblock
     * warns about: applying it to SiteTree lets the page opt in, but each
     * concrete element class still needs its own content_api_access/
     * api_access declaration — being nested under an opted-in page is not
     * enough. setUp() only grants ApiTestPage its own access; ApiTestElement
     * never gets one in this test, unlike testGrantsCanCreateOnABaseElementSubclass.
     */
    public function testElementWithoutItsOwnDeclarationIsNeverGranted(): void
    {
        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertFalse((bool) ApiTestElement::singleton()->canCreate($member));
    }

    /**
     * The grant's uninherited scoping check does not consult
     * ClassRegistry's own exposure map (`models`/`discovery_roots`) at all
     * — a class's own content_api_access/api_access declaration is honoured
     * even if the content API itself could never route to that class. This
     * is a deliberate, documented trade-off (see the class docblock and
     * docs/en/04_security-model.md), not an oversight, so this test pins it
     * as intended behaviour rather than leaving it to be "discovered" as a
     * regression later.
     */
    public function testGrantWorksRegardlessOfClassRegistryModelsMapMembership(): void
    {
        $registry = ClassRegistry::singleton();
        $this->assertNull(
            $registry->refFor(ApiTestPage::class),
            'sanity check: ApiTestPage is not registered in ClassRegistry.models in this test'
        );

        $page = ApiTestPage::create(['Title' => 'Unmapped But Declared']);
        $page->write();
        $page->publishRecursive();

        $member = $this->memberWithCodes([ContentApiPermissions::ACCESS]);

        $this->assertTrue((bool) $page->canEdit($member));
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
