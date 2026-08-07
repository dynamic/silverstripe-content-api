<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;

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
}
