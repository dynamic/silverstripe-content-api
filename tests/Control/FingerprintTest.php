<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Verify\FingerprintService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * End-to-end coverage for #131's `GET fingerprint` — the path-keyed
 * content snapshot and its reachability invariant (`violations`). Exercises
 * the behaviors called out in the Phase 1 plan: path keying across a
 * reparent, deterministic (byte-stable) ordering independent of insertion
 * order, the "Race Team" scenario (a live page under a draft-only parent),
 * unresolved-owner bucketing for `related` records, and permission-filtered
 * classes surfacing in `meta.skipped` rather than silently shrinking the
 * diffable payload.
 */
class FingerprintTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');

        Config::modify()->set(FingerprintService::class, 'related_classes', [
            'ApiTestFingerprintRelated' => 'PageID',
        ]);
    }

    private function inDraft(callable $callback): mixed
    {
        return Versioned::withVersionedMode(function () use ($callback) {
            Versioned::set_stage(Versioned::DRAFT);

            return $callback();
        });
    }

    private function createPage(string $urlSegment, int $parentId = 0, bool $publish = true): SiteTree
    {
        return $this->inDraft(function () use ($urlSegment, $parentId, $publish) {
            /** @var SiteTree $page */
            $page = ApiTestPage::create([
                'Title' => $urlSegment,
                'URLSegment' => $urlSegment,
                'ParentID' => $parentId,
            ]);
            $page->write();

            if ($publish) {
                $page->publishSingle();
            }

            return $page;
        });
    }

    private function fingerprint(string $query = ''): array
    {
        return $this->decode($this->apiGet('fingerprint' . $query, $this->adminToken));
    }

    private function pageEntry(array $body, string $path): ?array
    {
        foreach ($body['data']['pages'] as $entry) {
            if ($entry['path'] === $path) {
                return $entry;
            }
        }

        return null;
    }

    public function testPathReflectsCurrentParentAfterAReparent(): void
    {
        $section = $this->createPage('fp-section-a');
        $child = $this->createPage('fp-widget', $section->ID);

        $body = $this->fingerprint();
        $this->assertNotNull($this->pageEntry($body, '/fp-section-a/fp-widget'));

        $this->inDraft(function () use ($child) {
            $child->ParentID = 0;
            $child->write();
            $child->publishSingle();
        });

        $body = $this->fingerprint();
        $this->assertNull(
            $this->pageEntry($body, '/fp-section-a/fp-widget'),
            'the stale pre-reparent path must not still appear'
        );
        $this->assertNotNull($this->pageEntry($body, '/fp-widget'));
    }

    /**
     * Insertion order (and therefore id order) deliberately does NOT match
     * path order here — `zzz` is created first, `aaa` last — so a
     * regression to id-ordered or insertion-ordered output would fail this
     * even though every individual row is still correct.
     */
    public function testOrderingIsSortedByPathRegardlessOfInsertionOrder(): void
    {
        $this->createPage('fp-zzz-page');
        $this->createPage('fp-mmm-page');
        $this->createPage('fp-aaa-page');

        $body = $this->fingerprint();

        $ours = array_values(array_filter(
            $body['data']['pages'],
            static fn (array $entry) => str_starts_with($entry['path'], '/fp-')
                && str_ends_with($entry['path'], '-page')
        ));
        $ourPaths = array_column($ours, 'path');

        $this->assertSame(['/fp-aaa-page', '/fp-mmm-page', '/fp-zzz-page'], $ourPaths);
    }

    /**
     * The "Race Team" scenario the issue is named for: a live child
     * directly below a draft-only parent is unreachable in production
     * (`SiteTree::get_by_link()` 404s on the draft-only segment) even
     * though the child's own row is live. Exactly one violation, naming
     * the child's own path and the parent's as the blocker.
     */
    public function testLiveChildUnderADraftOnlyParentIsExactlyOneViolation(): void
    {
        $parent = $this->createPage('fp-race-team', 0, publish: false);
        $this->createPage('fp-schedule', $parent->ID);

        $body = $this->fingerprint();

        $violations = array_values(array_filter(
            $body['data']['violations'],
            static fn (array $v) => $v['path'] === '/fp-race-team/fp-schedule'
        ));

        $this->assertCount(1, $violations);
        $this->assertSame(['/fp-race-team'], $violations[0]['blockedBy']);

        // The parent itself isn't live, so it can't be "blocked" by
        // anything — only a live page can be unreachable.
        $this->assertCount(0, array_filter(
            $body['data']['violations'],
            static fn (array $v) => $v['path'] === '/fp-race-team'
        ));
    }

    /**
     * A draft-only parent with a draft-only child is simply an unpublished
     * branch, not a reachability problem — no violation should be reported
     * when nothing underneath is actually live.
     */
    public function testDraftOnlyBranchIsNotAViolation(): void
    {
        $parent = $this->createPage('fp-draft-branch', 0, publish: false);
        $this->createPage('fp-draft-child', $parent->ID, publish: false);

        $body = $this->fingerprint();

        $this->assertCount(0, array_filter(
            $body['data']['violations'],
            static fn (array $v) => str_starts_with($v['path'], '/fp-draft-branch')
        ));
    }

    public function testUnresolvedOwnerIsBucketedNotIdentifiedById(): void
    {
        $orphan = $this->inDraft(function () {
            $record = ApiTestFingerprintRelatedObject::create([
                'Title' => 'Orphaned',
                'PageID' => 999999999,
            ]);
            $record->write();
            $record->publishSingle();

            return $record;
        });

        $body = $this->fingerprint();

        $this->assertSame(1, $body['data']['related']['ApiTestFingerprintRelated']['unresolved']);

        foreach ($body['data']['related']['ApiTestFingerprintRelated']['records'] as $row) {
            $this->assertArrayNotHasKey(
                'id',
                $row,
                'an unresolved owner must never leak as a raw id, even indirectly via another row'
            );
        }

        // Confirm it's excluded from the records list entirely, not
        // included with a null/placeholder ownerPath.
        $orphanRows = array_filter(
            $body['data']['related']['ApiTestFingerprintRelated']['records'],
            fn (array $row) => ($row['externalId'] ?? null) === null
                && $row['className'] === ApiTestFingerprintRelatedObject::class
        );
        $this->assertCount(0, $orphanRows, 'the orphaned record must not appear as a resolved row');

        // Sanity: the fixture write actually happened.
        $this->assertNotNull($orphan->ID);
    }

    public function testResolvedOwnerReportsOwnerPathAndLiveStatus(): void
    {
        $page = $this->createPage('fp-hero-owner');

        $this->inDraft(function () use ($page) {
            $record = ApiTestFingerprintRelatedObject::create([
                'Title' => 'Hero',
                'PageID' => $page->ID,
            ]);
            $record->write();
            $record->publishSingle();
        });

        $body = $this->fingerprint();

        $rows = array_values(array_filter(
            $body['data']['related']['ApiTestFingerprintRelated']['records'],
            static fn (array $row) => $row['ownerPath'] === '/fp-hero-owner'
        ));

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['live']);
        $this->assertArrayNotHasKey('id', $rows[0], 'ids must not appear unless includeIds=1');
    }

    /**
     * The proactive fix made ahead of any review: a related record's own
     * owner page being live isn't enough — if that owner's ancestor isn't
     * live, the related record is just as unreachable as a page would be
     * in the same position. Mirrors the page-level Race Team scenario one
     * hop further out via `PageID` rather than `ParentID`.
     */
    public function testLiveRelatedRecordUnderALiveOwnerWithADraftOnlyGrandparentIsAViolation(): void
    {
        $grandparent = $this->createPage('fp-related-grandparent', 0, publish: false);
        $owner = $this->createPage('fp-related-owner', $grandparent->ID);

        $this->inDraft(function () use ($owner) {
            $record = ApiTestFingerprintRelatedObject::create([
                'Title' => 'Stranded hero',
                'PageID' => $owner->ID,
            ]);
            $record->write();
            $record->publishSingle();
        });

        $body = $this->fingerprint();

        $violations = array_values(array_filter(
            $body['data']['violations'],
            static fn (array $v) => $v['className'] === ApiTestFingerprintRelatedObject::class
                && $v['path'] === '/fp-related-grandparent/fp-related-owner'
        ));

        $this->assertCount(1, $violations);
        $this->assertSame(['/fp-related-grandparent'], $violations[0]['blockedBy']);
    }

    public function testIncludeIdsAddsIdOnlyWhenRequested(): void
    {
        $this->createPage('fp-with-id');

        $withoutIds = $this->fingerprint();
        $entry = $this->pageEntry($withoutIds, '/fp-with-id');
        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('id', $entry);

        $withIds = $this->fingerprint('?includeIds=1');
        $entry = $this->pageEntry($withIds, '/fp-with-id');
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('id', $entry);
        $this->assertIsInt($entry['id']);
    }

    public function testClassesParamRestrictsSections(): void
    {
        $this->createPage('fp-classes-page');
        $this->inDraft(function () {
            $record = ApiTestFingerprintRelatedObject::create(['Title' => 'X', 'PageID' => 0]);
            $record->write();
        });

        $body = $this->fingerprint('?classes=ApiTestFingerprintRelated');

        $this->assertSame([], $body['data']['pages'], 'pages must be excluded when not requested');
        $this->assertArrayHasKey('ApiTestFingerprintRelated', $body['data']['related']);
    }

    /**
     * Code-review regression test (self-caught before any review): the
     * internal page path index that `related` owner resolution depends on
     * must stay intact even when `classes=` excludes `pages` from the
     * OUTPUT — the first version of this gated index-building itself on
     * the same flag, so a `?classes=ApiTestFingerprintRelated`-only
     * request made every related row falsely `unresolved` regardless of
     * whether its owner page actually existed.
     */
    public function testClassesParamExcludingPagesStillResolvesRelatedOwnerPaths(): void
    {
        $page = $this->createPage('fp-classes-owner');

        $this->inDraft(function () use ($page) {
            $record = ApiTestFingerprintRelatedObject::create(['Title' => 'X', 'PageID' => $page->ID]);
            $record->write();
            $record->publishSingle();
        });

        $body = $this->fingerprint('?classes=ApiTestFingerprintRelated');

        $this->assertSame([], $body['data']['pages']);
        $this->assertSame(0, $body['data']['related']['ApiTestFingerprintRelated']['unresolved']);

        $rows = array_values(array_filter(
            $body['data']['related']['ApiTestFingerprintRelated']['records'],
            static fn (array $row) => $row['ownerPath'] === '/fp-classes-owner'
        ));
        $this->assertCount(1, $rows, 'owner path must still resolve even though pages is excluded from output');
    }

    /**
     * A related class the caller's token can't read must be reported in
     * `meta.skipped`, not silently omitted — a diff between two callers
     * with different permissions must not look like "no drift" when it's
     * actually "couldn't see this class."
     */
    public function testUnreadableRelatedClassIsSkippedNotSilentlyOmitted(): void
    {
        Config::modify()->set(ApiTestFingerprintRelatedObject::class, 'api_access', false);

        $body = $this->fingerprint();

        $this->assertContains('ApiTestFingerprintRelated', $body['meta']['skipped']);
        $this->assertArrayNotHasKey('ApiTestFingerprintRelated', $body['data']['related']);
    }

    public function testFingerprintRequiresBaselinePermission(): void
    {
        $response = $this->apiGet('fingerprint', $this->mintTokenFor('noAccessUser'));

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testTotalsCountDraftAndLiveIndependentlyOfViolations(): void
    {
        $this->createPage('fp-totals-live');
        $this->createPage('fp-totals-draft', 0, publish: false);

        $body = $this->fingerprint();

        $this->assertGreaterThanOrEqual(2, $body['data']['totals']['pages']['draft']);
        $this->assertGreaterThanOrEqual(1, $body['data']['totals']['pages']['live']);
    }
}
