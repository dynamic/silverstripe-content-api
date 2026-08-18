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

    /**
     * #130 code-review regression: `dryRun` only ever means anything for
     * `publish` mode "subtree" — unpublish/archive have no dry-run
     * support at all and must reject the flag outright rather than
     * silently performing the real, irreversible action.
     */
    public function testUnpublishAndArchiveRejectDryRunNotSilentlyIgnoringIt(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $unpublishTarget = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');
        $unpublishTarget->publishRecursive();

        $unpublishResponse = $this->apiPost(
            "records/ApiTestVersioned/{$unpublishTarget->ID}/unpublish",
            ['dryRun' => true],
            $token
        );
        $this->assertErrorCode($unpublishResponse, 'PAYLOAD_INVALID', 400);
        $this->assertTrue(
            Versioned::get_by_stage(ApiTestVersionedObject::class, Versioned::LIVE)
                ->filter('ID', $unpublishTarget->ID)->exists(),
            'nothing should have run — the record must still be live'
        );

        $archiveTarget = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $archiveResponse = $this->apiPost(
            "records/ApiTestVersioned/{$archiveTarget->ID}/archive",
            ['dryRun' => true],
            $token
        );
        $this->assertErrorCode($archiveResponse, 'PAYLOAD_INVALID', 400);
        $this->assertNotNull(
            ApiTestVersionedObject::get()->byID($archiveTarget->ID),
            'nothing should have run — the record must not have been archived'
        );
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

    /**
     * #80: force-unpublish's cascade is delete-shaped (the same live-subtree
     * loss archive produces), so it must require the 'delete' verb at the
     * class level too — not just 'action' — mirroring archive's existing
     * split (see testArchiveRequiresClassDeleteVerb above). Plain,
     * non-forced unpublish must stay reachable on 'action' alone.
     */
    public function testForceUnpublishRequiresClassDeleteVerb(): void
    {
        \SilverStripe\Core\Config\Config::modify()
            ->set(ApiTestVersionedObject::class, 'api_access', 'read,action');

        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token);

        $plainUnpublish = $this->apiPost("records/ApiTestVersioned/{$record->ID}/unpublish", [], $token);
        $this->assertSame(
            200,
            $plainUnpublish->getStatusCode(),
            'plain unpublish must stay reachable on "action" alone, without "delete"'
        );

        // Republish so the force call below has something to act on.
        $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token);

        $forced = $this->apiPost("records/ApiTestVersioned/{$record->ID}/unpublish", ['force' => true], $token);
        $this->assertErrorCode($forced, 'FORBIDDEN_CLASS', 403);
    }

    /**
     * Record-level half of the same split (#80), mirroring
     * testArchiveRequiresCanDeleteNotCanEdit: ApiTestVersionedObject's
     * default api_access (set in ContentApiTestCase::setUp) grants the
     * class-level 'delete' verb, but apiUser's own canDelete() is
     * ADMIN-only — force-unpublish must still be refused at the record
     * gate.
     */
    public function testForceUnpublishRequiresRecordDeleteVerb(): void
    {
        $token = $this->mintTokenFor('apiUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token);

        $forced = $this->apiPost("records/ApiTestVersioned/{$record->ID}/unpublish", ['force' => true], $token);
        $this->assertErrorCode($forced, 'FORBIDDEN_RECORD', 403);
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

    /**
     * A real (non-dryRun) subtree call must report what it actually
     * published — meta.published lists what was touched, not what was
     * skipped (there is no separate skipped list; a liveOnly-skipped
     * descendant simply never appears). Without this, a caller had no way
     * to confirm what a real liveOnly run actually did short of a
     * separate dryRun call beforehand, which isn't atomic with the real
     * one (#102). Found via /review-pr on #113.
     */
    public function testRealSubtreePublishReportsWhatWasActuallyPublished(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $root = ApiTestPage::create(['Title' => 'Subtree Meta Root']);
        $root->write();
        $root->publishRecursive();

        $offline = ApiTestPage::create(['Title' => 'Subtree Meta Offline', 'ParentID' => $root->ID]);
        $offline->write();
        $offline->publishRecursive();
        $offline->doUnpublish();

        $stillLive = ApiTestPage::create(['Title' => 'Subtree Meta Still Live', 'ParentID' => $root->ID]);
        $stillLive->write();
        $stillLive->publishRecursive();

        $response = $this->decode($this->apiPost(
            "records/ApiTestPage/{$root->ID}/publish",
            ['mode' => 'subtree', 'liveOnly' => true],
            $token
        ));

        $this->assertSame('published', $response['meta']['operation']);
        $publishedIDs = array_column($response['meta']['published'], 'id');
        $this->assertContains((int) $root->ID, $publishedIDs);
        $this->assertContains((int) $stillLive->ID, $publishedIDs);
        $this->assertNotContains(
            (int) $offline->ID,
            $publishedIDs,
            'liveOnly must not publish a deliberately-offline descendant, so it must not appear in meta.published'
        );

        // The normal serialized-record response is still the primary
        // payload — meta.published is additive, not a replacement.
        $this->assertSame((int) $root->ID, $response['data']['id']);
    }

    /**
     * dryRun/liveOnly silently no-op-ing on a non-subtree mode would let a
     * caller send {"dryRun": true} (or omit "mode" entirely, which
     * defaults to "single") and get a REAL write while the response reads
     * like nothing happened. Must refuse instead. Found via /review-pr on
     * #113.
     */
    public function testDryRunOnANonSubtreeModeIsRejectedNotSilentlyIgnored(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $page = ApiTestPage::create(['Title' => 'DryRun Wrong Mode']);
        $page->write();

        // No "mode" key at all — publishModeFromBody() defaults to
        // "single", the exact case that silently wrote for real before
        // this fix.
        $response = $this->apiPost("records/ApiTestPage/{$page->ID}/publish", ['dryRun' => true], $token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
        $this->assertFalse(
            Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $page->ID)->exists(),
            'a refused dryRun request must not have published the record'
        );
    }

    public function testLiveOnlyOnAnExplicitNonSubtreeModeIsAlsoRejected(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $page = ApiTestPage::create(['Title' => 'LiveOnly Wrong Mode']);
        $page->write();

        $response = $this->apiPost(
            "records/ApiTestPage/{$page->ID}/publish",
            ['mode' => 'recursive', 'liveOnly' => true],
            $token
        );

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
        $this->assertFalse(
            Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $page->ID)->exists()
        );
    }

    public function testSubtreeDryRunViaHttpReturnsThePreviewEnvelopeWithoutWriting(): void
    {
        $token = $this->mintTokenFor('adminUser');

        $root = ApiTestPage::create(['Title' => 'DryRun Envelope Root']);
        $root->write();

        $child = ApiTestPage::create(['Title' => 'DryRun Envelope Child', 'ParentID' => $root->ID]);
        $child->write();

        $response = $this->decode($this->apiPost(
            "records/ApiTestPage/{$root->ID}/publish",
            ['mode' => 'subtree', 'dryRun' => true],
            $token
        ));

        $this->assertSame('publishDryRun', $response['meta']['operation']);
        $this->assertSame('subtree', $response['meta']['mode']);
        $previewedIDs = array_column($response['data']['wouldPublish'], 'id');
        $this->assertContains((int) $root->ID, $previewedIDs);
        $this->assertContains((int) $child->ID, $previewedIDs);

        $this->assertFalse(
            Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $root->ID)->exists(),
            'dryRun must never write, over HTTP any more than at the orchestrator level'
        );
    }
}
