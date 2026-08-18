<?php

namespace Dynamic\ContentApi\Tests\Control;

use DNADesign\Elemental\Models\ElementalArea;
use Dynamic\ContentApi\Control\Handlers\PageHandler;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestElementItem;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestPlainChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTemplateApplicator;
use Dynamic\ContentApi\Tests\Stub\ApiTestTemplateModel;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

class PageActionsTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');

        Config::modify()->set(SiteTree::class, 'api_access', 'read,update,action');
        Config::modify()->merge(
            \Dynamic\ContentApi\Registry\ClassRegistry::class,
            'models',
            ['SiteTree' => SiteTree::class]
        );
    }

    public function testUrlSegmentCollisionIsReportedNotHidden(): void
    {
        $contact = $this->objFromFixture(SiteTree::class, 'contactPage');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'SiteTree',
                    'id' => (int) $contact->ID,
                    'fields' => ['URLSegment' => 'about'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);

        $warnings = $body['data']['results'][0]['warnings'] ?? [];
        $this->assertNotEmpty($warnings, 'Expected a URLSEGMENT_COLLISION warning.');
        $this->assertSame('URLSEGMENT_COLLISION', $warnings[0]['code']);

        $fresh = SiteTree::get()->byID($contact->ID);
        $this->assertNotSame('about', $fresh->URLSegment);
        $this->assertStringStartsWith('about-', $fresh->URLSegment);
    }

    public function testConvertPage(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $this->adminToken);

        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('converted', $body['meta']['operation']);
        $this->assertSame(ApiTestPage::class, $body['data']['className']);
        $this->assertSame(ApiTestPage::class, SiteTree::get()->byID($about->ID)->ClassName);
    }

    /**
     * #130: dryRun is a `POST batch` feature only — rejected outright on
     * page actions rather than silently ignored, same convention as
     * #102's dryRun/liveOnly rejection on non-subtree publish modes.
     */
    public function testConvertRejectsDryRunNotSilentlyIgnored(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
            'dryRun' => true,
        ], $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
        $this->assertSame(SiteTree::class, SiteTree::get()->byID($about->ID)->ClassName, 'nothing should have run');
    }

    public function testConvertToSameClassIsUnchanged(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'SiteTree',
        ], $this->adminToken);

        $body = $this->decode($response);

        $this->assertSame('unchanged', $body['meta']['operation']);
    }

    public function testConvertHomePageRefusedWithoutForce(): void
    {
        $home = $this->objFromFixture(SiteTree::class, 'homePage');

        $response = $this->apiPost("pages/{$home->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'HOMEPAGE_CONVERSION_FORBIDDEN', 403);

        // force: true proceeds.
        $forced = $this->apiPost("pages/{$home->ID}/convert", [
            'className' => 'ApiTestPage',
            'force' => true,
        ], $this->adminToken);

        $this->assertSame(200, $forced->getStatusCode(), (string) $forced->getBody());
    }

    /**
     * #114: only the *pre-conversion* record's `update` verb was ever
     * checked — the *target* class's own verbs were never checked at all
     * before publishing the converted instance as root. Narrowing
     * ApiTestPage's own api_access (normally `true`, granted in
     * ContentApiTestCase::setUp) to omit `update` must now refuse the
     * conversion, even though the source class (`SiteTree`, this test
     * file's own setUp) grants everything.
     */
    public function testConvertRequiresTargetClassUpdateVerb(): void
    {
        Config::modify()->set(ApiTestPage::class, 'api_access', 'read');

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
        $this->assertSame(
            SiteTree::class,
            SiteTree::get()->byID($about->ID)->ClassName,
            'nothing should have converted'
        );
    }

    /**
     * The `action` half of the same gap: a target class granting `update`
     * but not `action` must refuse a conversion that also wants to
     * publish, but must still allow one that doesn't ("publish": "none",
     * the default).
     */
    public function testConvertWithPublishModeRequiresTargetClassActionVerb(): void
    {
        Config::modify()->set(ApiTestPage::class, 'api_access', 'read,update');

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
            'publish' => 'recursive',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testConvertWithoutAPublishModeDoesNotRequireTargetClassActionVerb(): void
    {
        Config::modify()->set(ApiTestPage::class, 'api_access', 'read,update');

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testConvertToNonPageClassRejected(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTest',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testConvertRequiresPopulatePermission(): void
    {
        $token = $this->mintTokenFor('apiUser');
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $token);

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testConvertIsEnvironmentGated(): void
    {
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ENV_FORBIDDEN', 403);
    }

    public function testUnknownPageActionIs404(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $response = $this->apiPost("pages/{$about->ID}/explode", [], $this->adminToken);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testConvertValidationFailureDoesNotLeakRawExceptionText(): void
    {
        // Plain SiteTree has no validate() override, so this page can be
        // titled "Invalid" and saved; ApiTestPage's validate() rejects that
        // title, which the conversion carries over unchanged.
        $page = SiteTree::create(['Title' => 'Invalid']);
        $page->write();

        $response = $this->apiPost("pages/{$page->ID}/convert", [
            'className' => 'ApiTestPage',
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        // Prefixed with which operation failed, but no raw exception
        // text (#21).
        $this->assertSame('Page conversion: 1 field(s) failed validation.', $body['error']['message']);
        $this->assertSame('Title', $body['error']['details'][0]['field']);
    }

    /**
     * Points `apply-template` at the test stubs. The real
     * dynamic/silverstripe-elemental-templates package is `suggest`ed and
     * never installed here, so without this the endpoint short-circuits on
     * its own `class_exists()` gate and nothing below it has ever run under
     * test (#174).
     */
    private function useTemplateStubs(): void
    {
        Config::modify()->set(PageHandler::class, 'template_class', ApiTestTemplateModel::class);
        Config::modify()->set(PageHandler::class, 'template_applicator_class', ApiTestTemplateApplicator::class);
    }

    /**
     * A template holding one element that carries both a versioned
     * (`Items`) and an unversioned (`PlainItems`) has_many child, both
     * listed in `ApiTestElement::$cascade_duplicates` — so applying it
     * duplicates the children too, which is the shape #174 is about.
     */
    private function templateWithChildBearingElement(): ApiTestTemplateModel
    {
        return $this->inDraft(function () {
            $template = ApiTestTemplateModel::create(['Title' => 'Hero template']);
            $template->write();

            $area = ElementalArea::create();
            $area->write();

            $template->ElementsID = $area->ID;
            $template->write();

            $element = ApiTestElement::create(['Title' => 'Source element', 'Intro' => 'Hello']);
            $element->ParentID = $area->ID;
            $element->write();

            $item = ApiTestElementItem::create(['Title' => 'Versioned child']);
            $item->ElementID = $element->ID;
            $item->write();

            $plain = ApiTestPlainChildObject::create(['Title' => 'Unversioned child']);
            $plain->ElementID = $element->ID;
            $plain->write();

            return $template;
        });
    }

    /**
     * Written in an explicit DRAFT stage, which Elemental 6 requires before
     * it will create the page's `ElementalArea` at all:
     * `ElementalAreasExtension::allowAlteringElementalArea()` gates
     * `ensureElementalAreasExist()` on `Versioned::get_stage() === DRAFT`.
     * Elemental 5 has no such guard, so a bare `write()` happens to work
     * there and this looks unnecessary until it's run on the other branch.
     * Also just accurate: every content-api write path operates in draft.
     */
    private function blockPage(): ApiTestBlockPage
    {
        return $this->inDraft(function () {
            $page = ApiTestBlockPage::create(['Title' => 'Template target']);
            $page->write();

            return $page;
        });
    }

    private function inDraft(callable $callback): mixed
    {
        return Versioned::withVersionedMode(function () use ($callback) {
            Versioned::set_stage(Versioned::DRAFT);

            return $callback();
        });
    }

    /**
     * The #174 regression test, and the first test of any kind to exercise
     * `apply-template`'s publish behavior.
     *
     * `TemplateElementDuplicator` duplicates each template element with a
     * bare `$element->duplicate()`, and `DataObject::duplicate()` falls back
     * to `$cascade_duplicates` when given no relation list — so the element's
     * own has_many children come along. `BaseElement` declares no `$owns`,
     * so those children are not reachable by `publishOwnedTree()`'s walk;
     * before the fix they stayed on draft after a `"publish": "recursive"`
     * apply, with no error and no signal that anything had been missed.
     */
    public function testApplyTemplateRecursivePublishesElementChildren(): void
    {
        $this->useTemplateStubs();

        $page = $this->blockPage();
        $template = $this->templateWithChildBearingElement();

        $response = $this->apiPost("pages/{$page->ID}/apply-template", [
            'templateId' => (int) $template->ID,
            'publish' => 'recursive',
        ], $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $copied = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea()->Elements()->first();
        $this->assertInstanceOf(ApiTestElement::class, $copied);
        $this->assertTrue($copied->isPublished(), 'the duplicated element itself publishes');

        $child = $copied->Items()->first();
        $this->assertInstanceOf(
            ApiTestElementItem::class,
            $child,
            'duplicate() should have cascaded the has_many child onto the copy'
        );
        $this->assertTrue(
            $child->isPublished(),
            'the element child duplicate() created must publish too — this is #174'
        );
    }

    /**
     * `ApiTestElement::$cascade_duplicates` also names `PlainItems`, whose
     * class isn't Versioned.
     *
     * Note what this does and does not cover: `OwnedTreeWalker` never emits
     * an unversioned record, so the child never even reaches
     * `publishOwnedTree()` — its own drop-with-a-warning branch is exercised
     * by the composition tests, not here. What this pins is that an
     * unversioned child neither fails the call nor goes missing from the
     * duplicate.
     */
    public function testApplyTemplateToleratesUnversionedElementChildren(): void
    {
        $this->useTemplateStubs();

        $page = $this->blockPage();
        $template = $this->templateWithChildBearingElement();

        $response = $this->apiPost("pages/{$page->ID}/apply-template", [
            'templateId' => (int) $template->ID,
            'publish' => 'recursive',
        ], $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $copied = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea()->Elements()->first();
        $this->assertCount(1, $copied->PlainItems(), 'the unversioned child still gets duplicated');
    }

    /**
     * The new targets go through the same authorization as every other
     * cascade member, and the whole action still rolls back when one is
     * refused — the `DbTransaction` wrapper has to cover the element
     * children, not just the area and elements.
     */
    public function testApplyTemplateRollsBackWhenAnElementChildWithholdsAction(): void
    {
        $this->useTemplateStubs();

        Config::modify()->set(ApiTestElementItem::class, 'api_access', 'read,update');

        $page = $this->blockPage();
        $template = $this->templateWithChildBearingElement();

        $response = $this->apiPost("pages/{$page->ID}/apply-template", [
            'templateId' => (int) $template->ID,
            'publish' => 'recursive',
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);

        $this->assertCount(
            0,
            ApiTestBlockPage::get()->byID($page->ID)->ElementalArea()->Elements(),
            'the draft write must roll back with the refused publish'
        );
    }

    /**
     * Guard against the new per-element walk running unconditionally: with
     * no publish mode the template is applied to draft and nothing is
     * published at all.
     */
    public function testApplyTemplateWithoutPublishModePublishesNothing(): void
    {
        $this->useTemplateStubs();

        $page = $this->blockPage();
        $template = $this->templateWithChildBearingElement();

        $response = $this->apiPost("pages/{$page->ID}/apply-template", [
            'templateId' => (int) $template->ID,
        ], $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $copied = ApiTestBlockPage::get()->byID($page->ID)->ElementalArea()->Elements()->first();
        $this->assertInstanceOf(ApiTestElement::class, $copied, 'the draft write still happens');
        $this->assertFalse($copied->isPublished());
        $this->assertFalse($copied->Items()->first()->isPublished());
    }

    /**
     * The `class_exists()` gate is unchanged by making the class names
     * config — a project without the package still gets FEATURE_UNAVAILABLE
     * rather than a fatal on a missing class.
     *
     * Points the config at a deliberately absent class rather than relying on
     * the real package being uninstalled: it isn't installed alongside this
     * module's SS5 test run, but it IS present in the SS6 testbed, where
     * relying on its absence made this test assert nothing.
     */
    public function testApplyTemplateWithoutThePackageIsFeatureUnavailable(): void
    {
        Config::modify()->set(PageHandler::class, 'template_class', 'Dynamic\\ContentApi\\Tests\\NoSuchTemplate');

        $page = $this->blockPage();

        $response = $this->apiPost("pages/{$page->ID}/apply-template", [
            'templateId' => 1,
        ], $this->adminToken);

        $this->assertErrorCode($response, 'FEATURE_UNAVAILABLE', 501);
    }

    /**
     * The walk runs over `$area->Elements()` — every element on the page, not
     * only the ones the template just added — so it reaches the children of
     * pre-existing, untouched elements too, publishing them.
     *
     * That breadth is inherited from the endpoint's pre-existing behavior
     * (which already published every element on the page, just not their
     * children) and is deliberately not narrowed here. Pinning it so the
     * decision is visible rather than incidental: it is the surface most
     * likely to surprise an existing deployment, since an unrelated template
     * application now pushes an untouched element's draft-only child live.
     */
    public function testApplyTemplateAlsoPublishesPreExistingElementsChildren(): void
    {
        $this->useTemplateStubs();

        $page = $this->blockPage();

        $existing = ApiTestElement::create(['Title' => 'Already here']);
        $existing->ParentID = $page->ElementalArea()->ID;
        $existing->write();

        $existingChild = ApiTestElementItem::create(['Title' => 'Pre-existing child']);
        $existingChild->ElementID = $existing->ID;
        $existingChild->write();

        $this->assertFalse($existingChild->isPublished(), 'precondition: draft only');

        $template = $this->templateWithChildBearingElement();

        $response = $this->apiPost("pages/{$page->ID}/apply-template", [
            'templateId' => (int) $template->ID,
            'publish' => 'recursive',
        ], $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertTrue(
            ApiTestElementItem::get()->byID($existingChild->ID)->isPublished(),
            'a pre-existing element\'s child is published too — deliberate, see the handler comment'
        );
    }
}
