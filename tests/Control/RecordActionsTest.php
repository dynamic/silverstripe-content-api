<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Versioned\Versioned;

class RecordActionsTest extends ContentApiTestCase
{
    public function testRecordStageActions(): void
    {
        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        // publish
        $published = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token)
        );
        $this->assertTrue($published['data']['stage']['live']);

        // unpublish
        $unpublished = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/unpublish", [], $token)
        );
        $this->assertFalse($unpublished['data']['stage']['live']);

        // archive
        $archived = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/archive", [], $token)
        );
        $this->assertTrue($archived['data']['archived']);

        $miss = $this->apiGet("records/ApiTestVersioned/{$record->ID}", $token);
        $this->assertErrorCode($miss, 'NOT_FOUND', 404);
    }

    public function testUnknownRecordActionIs404(): void
    {
        $token = $this->mintTokenFor('apiUser');
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPost("records/ApiTest/{$record->ID}/explode", [], $token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    /**
     * Regression for #45: archive is a soft-delete from both stages, so it
     * must gate on canDelete(), independent of canEdit(). ApiTestVersionedObject
     * grants canEdit() to any member but canDelete() to ADMIN only — apiUser
     * (CONTENT_API_ACCESS, no ADMIN) can publish/unpublish (see
     * testApiUserCanPublishAndUnpublishWithoutCanDelete for that positive
     * half of the split) but not archive.
     */
    public function testArchiveRequiresCanDeleteNotCanEdit(): void
    {
        $token = $this->mintTokenFor('apiUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $response = $this->apiPost("records/ApiTestVersioned/{$record->ID}/archive", [], $token);
        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
    }

    /**
     * Regression for #45 code review: the positive half of the canEdit()/
     * canDelete() split — asserted in prose by the neighbouring test's
     * docblock but never actually exercised anywhere in the suite (only
     * adminUser's publish/unpublish is tested, in testRecordStageActions).
     * If publish/unpublish were ever mistakenly also moved onto the
     * 'delete' verb, no existing test would have caught it.
     */
    public function testApiUserCanPublishAndUnpublishWithoutCanDelete(): void
    {
        $token = $this->mintTokenFor('apiUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $published = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token)
        );
        $this->assertTrue($published['data']['stage']['live']);

        $unpublished = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/unpublish", [], $token)
        );
        $this->assertFalse($unpublished['data']['stage']['live']);
    }

    /**
     * Regression for #45: the class-level gate must also vary by action —
     * a class exposing 'action' but not 'delete' must reject archive at the
     * class level even for a member whose canDelete() would otherwise allow it.
     */
    public function testArchiveRequiresClassDeleteVerb(): void
    {
        \SilverStripe\Core\Config\Config::modify()
            ->set(ApiTestVersionedObject::class, 'api_access', 'read,action');

        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $publish = $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token);
        $this->assertSame(200, $publish->getStatusCode());

        $response = $this->apiPost("records/ApiTestVersioned/{$record->ID}/archive", [], $token);
        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testActionRequiresVerb(): void
    {
        \SilverStripe\Core\Config\Config::modify()
            ->set(ApiTestVersionedObject::class, 'api_access', 'read');

        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $response = $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testRemovedCrudEndpointsAreGone(): void
    {
        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        // POST create — moved to colymba /api or batch.
        $create = $this->apiPost('records/ApiTest', ['fields' => ['Title' => 'X']], $token);
        $this->assertSame(404, $create->getStatusCode());

        // PATCH — moved to colymba PUT or batch update op.
        $patch = $this->apiPatch("records/ApiTest/{$record->ID}", ['fields' => ['Title' => 'X']], $token);
        $this->assertSame(404, $patch->getStatusCode());

        // DELETE — moved to colymba DELETE or batch delete op / archive action.
        $delete = $this->apiDelete("records/ApiTest/{$record->ID}", $token);
        $this->assertSame(404, $delete->getStatusCode());
    }

    /**
     * End-to-end coverage for #71's stranded-descendants guard, confirming
     * the HTTP action reads the "force" body flag through to
     * PublishOrchestrator::unpublish() — the unit-level guard logic itself
     * is covered directly in Publish/PublishOrchestratorTest.
     */
    public function testUnpublishActionRefusesThenSucceedsWithForce(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $wrapper = ApiTestPage::create(['Title' => 'Action Wrapper']);
        $wrapper->write();
        $wrapper->publishRecursive();

        $child = ApiTestPage::create(['Title' => 'Action Child', 'ParentID' => $wrapper->ID]);
        $child->write();
        $child->publishRecursive();

        $refused = $this->apiPost("records/ApiTestPage/{$wrapper->ID}/unpublish", [], $token);
        $this->assertErrorCode($refused, 'UNPUBLISH_STRANDS_DESCENDANTS', 409);
        $this->assertTrue(
            Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $wrapper->ID)->exists()
        );

        $forced = $this->decode(
            $this->apiPost("records/ApiTestPage/{$wrapper->ID}/unpublish", ['force' => true], $token)
        );
        $this->assertFalse($forced['data']['stage']['live']);
    }

    /**
     * archive() shares unpublish()'s guard — confirming the HTTP action
     * reads "force" through to PublishOrchestrator::archive() too.
     */
    public function testArchiveActionRefusesThenSucceedsWithForce(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $wrapper = ApiTestPage::create(['Title' => 'Archive Action Wrapper']);
        $wrapper->write();
        $wrapper->publishRecursive();

        $child = ApiTestPage::create(['Title' => 'Archive Action Child', 'ParentID' => $wrapper->ID]);
        $child->write();
        $child->publishRecursive();

        $refused = $this->apiPost("records/ApiTestPage/{$wrapper->ID}/archive", [], $token);
        $this->assertErrorCode($refused, 'UNPUBLISH_STRANDS_DESCENDANTS', 409);

        $forced = $this->decode(
            $this->apiPost("records/ApiTestPage/{$wrapper->ID}/archive", ['force' => true], $token)
        );
        $this->assertTrue($forced['data']['archived']);
    }

    /**
     * The publish action's own docs point a caller who hits
     * UNPUBLISH_STRANDS_DESCENDANTS at "publish the subtree to its new
     * parent first" — confirms mode=subtree is actually reachable from
     * this endpoint, not just via batch/content_page_convert.
     */
    public function testPublishActionAcceptsAnExplicitSubtreeMode(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $root = ApiTestPage::create(['Title' => 'Publish Action Subtree Root']);
        $root->write();

        $child = ApiTestPage::create(['Title' => 'Publish Action Subtree Child', 'ParentID' => $root->ID]);
        $child->write();

        $response = $this->decode(
            $this->apiPost("records/ApiTestPage/{$root->ID}/publish", ['mode' => 'subtree'], $token)
        );

        $this->assertTrue($response['data']['stage']['live']);
        $this->assertTrue(
            Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $child->ID)->exists(),
            'mode=subtree must reach PublishOrchestrator::publish() with the subtree mode, not silently ' .
                'fall back to single'
        );
    }
}
