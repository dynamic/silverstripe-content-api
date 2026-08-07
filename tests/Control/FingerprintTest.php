<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintNonVersionedRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Verify\FingerprintService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
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
            'ApiTestFingerprintNonVersionedRelated' => 'PageID',
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

    public function testUnresolvedOwnerIsBucketedNotIdentifiedByIdByDefault(): void
    {
        $page = $this->createPage('fp-unresolved-control');

        $orphan = $this->inDraft(function () {
            $record = ApiTestFingerprintRelatedObject::create([
                'Title' => 'Orphaned',
                'PageID' => 999999999,
            ]);
            $record->write();
            $record->publishSingle();

            return $record;
        });

        $this->inDraft(function () use ($page) {
            $record = ApiTestFingerprintRelatedObject::create(['Title' => 'Resolved', 'PageID' => $page->ID]);
            $record->write();
            $record->publishSingle();
        });

        $body = $this->fingerprint();

        $this->assertSame(1, $body['data']['related']['ApiTestFingerprintRelated']['unresolved']);
        $this->assertArrayNotHasKey(
            'unresolvedIds',
            $body['data']['related']['ApiTestFingerprintRelated'],
            'unresolvedIds must not appear at all unless includeIds is set'
        );

        // The one resolved row (not the orphan) must be the only row
        // present, and it must carry no id — includeIds wasn't requested.
        $this->assertCount(1, $body['data']['related']['ApiTestFingerprintRelated']['records']);
        $this->assertArrayNotHasKey('id', $body['data']['related']['ApiTestFingerprintRelated']['records'][0]);

        $this->assertNotNull($orphan->ID);
    }

    /**
     * Code-review regression test: `includeIds=1` is the endpoint's own
     * documented mechanism for debugging exactly this — "which record has
     * the broken owner FK" — but the orphan's id was previously dropped
     * unconditionally before a row was ever built, so `includeIds` had no
     * effect on unresolved owners at all despite the docs/changelog
     * claiming otherwise.
     */
    public function testIncludeIdsExposesUnresolvedOwnerIds(): void
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

        $body = $this->fingerprint('?includeIds=1');

        $this->assertSame(
            [(int) $orphan->ID],
            $body['data']['related']['ApiTestFingerprintRelated']['unresolvedIds']
        );
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
     * A related class not exposed to the content API at all (site config,
     * not caller-specific — see `testDraftOnlyPageInvisibleToATokenWithout
     * ViewDraftContent` for the actual per-caller row filtering) must be
     * reported in `meta.skipped`, not silently omitted — an operator
     * checking a fingerprint's `skipped` should never mistake "this class
     * was never exposed" for "no drift."
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

    public function testTotalsReflectPagesCreatedInThisTest(): void
    {
        $before = $this->fingerprint()['data']['totals']['pages'];

        $this->createPage('fp-totals-live');
        $this->createPage('fp-totals-draft', 0, publish: false);

        $after = $this->fingerprint()['data']['totals']['pages'];

        $this->assertSame($before['draft'] + 2, $after['draft']);
        $this->assertSame($before['live'] + 1, $after['live']);
    }

    /**
     * Critical fix, code-review regression test: a draft-only page (or one
     * whose draft/live stages otherwise diverge) is invisible via
     * `GET records/$ClassRef/$ID` to a member holding only
     * `CONTENT_API_ACCESS` — `Versioned::canViewVersioned()`'s core
     * fallback denies it without `VIEW_DRAFT_CONTENT` (see
     * docs/en/04_security-model.md). The fingerprint previously took no
     * `Member` at all and applied no record-level check, so it disclosed
     * every draft-only page's path/className/live-status to any holder of
     * bare `CONTENT_API_ACCESS` regardless of what they could actually
     * read via the record endpoints.
     */
    public function testDraftOnlyPageInvisibleToATokenWithoutViewDraftContent(): void
    {
        $this->createPage('fp-restricted-draft', 0, publish: false);

        $adminBody = $this->fingerprint();
        $this->assertNotNull(
            $this->pageEntry($adminBody, '/fp-restricted-draft'),
            'sanity: an admin token (bypasses all ACL) must see the draft-only page'
        );

        $plainToken = $this->mintTokenFor('apiUser');
        $plainBody = $this->decode($this->apiGet('fingerprint', $plainToken));

        $this->assertNull(
            $this->pageEntry($plainBody, '/fp-restricted-draft'),
            'a token without VIEW_DRAFT_CONTENT must not see a draft-only page via the fingerprint'
        );
    }

    /**
     * Critical fix, code-review regression test: the section-level "is
     * SiteTree exposed at all" gate previously ran once for the whole
     * tree — a project exposing a broad ancestor (or `Page` generally)
     * while explicitly denying one specific subclass
     * (`content_api_access: false`) had that denial silently overridden,
     * since the gate never re-checked each row's OWN class.
     */
    public function testASiteTreeSubclassWithExplicitlyDeniedAccessIsExcludedFromPages(): void
    {
        $this->createPage('fp-visible-page');

        Config::modify()->set(ApiTestBlockPage::class, 'api_access', false);

        $this->inDraft(function () {
            $page = ApiTestBlockPage::create(['Title' => 'Hidden', 'URLSegment' => 'fp-hidden-blockpage']);
            $page->write();
            $page->publishSingle();
        });

        $body = $this->fingerprint();

        $this->assertNotNull($this->pageEntry($body, '/fp-visible-page'));
        $this->assertNull($this->pageEntry($body, '/fp-hidden-blockpage'));
    }

    /**
     * Critical fix, code-review regression test: the exposure checks used
     * `accessVerbs(...) === []` (any verb at all), not a `read`-specific
     * check — a class configured for write-only access (e.g.
     * `content_api_access: 'create'`) has a non-empty verb list and
     * previously passed the gate despite `GET records/$ClassRef` 403ing
     * `FORBIDDEN_CLASS` for that same class.
     */
    public function testASiteTreeSubclassWithNonReadAccessIsExcludedFromPages(): void
    {
        $this->createPage('fp-write-only-visible');

        Config::modify()->set(ApiTestBlockPage::class, 'api_access', 'create');

        $this->inDraft(function () {
            $page = ApiTestBlockPage::create(['Title' => 'Write-only', 'URLSegment' => 'fp-write-only-page']);
            $page->write();
            $page->publishSingle();
        });

        $body = $this->fingerprint();

        $this->assertNotNull($this->pageEntry($body, '/fp-write-only-visible'));
        $this->assertNull($this->pageEntry($body, '/fp-write-only-page'));
    }

    public function testUnknownClassesRefIsRejected(): void
    {
        $response = $this->apiGet('fingerprint?classes=NotARealRef', $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * Code-review regression test: the previous fix for `classes=`
     * excluding `pages` from the output (while still resolving `related`
     * owner paths) re-gated the `skipped` write behind `$wantsPages` —
     * when pages is BOTH excluded by `classes=` AND genuinely unreadable,
     * `skipped` silently stayed empty and every related row fell into
     * `unresolved` with no signal why.
     */
    public function testPagesReportedInSkippedWhenUnreadableEvenIfClassesExcludesIt(): void
    {
        Config::modify()->set(ApiTestPage::class, 'api_access', false);
        Config::modify()->set(ApiTestBlockPage::class, 'api_access', false);
        // The testbed's own app/_config/content-api.yml exposes the real
        // `Page` class too — every SiteTree subclass this test suite could
        // possibly resolve as "exposed" must be denied for the section to
        // genuinely become unreadable.
        Config::modify()->set(\Page::class, 'api_access', false);

        $body = $this->fingerprint('?classes=ApiTestFingerprintRelated');

        $this->assertContains('pages', $body['meta']['skipped']);
    }

    /**
     * Code-review regression test: `violations` was the one collection
     * never sorted (`pages`/`related.records` both were), so the
     * "determinism is the whole point" guarantee didn't actually hold for
     * it — the identical site fingerprinted twice could emit its
     * violations in a different order with zero content actually
     * different.
     */
    public function testViolationsAreSortedByPath(): void
    {
        $zParent = $this->createPage('fp-violation-zzz', 0, publish: false);
        $this->createPage('fp-violation-zzz-child', $zParent->ID);

        $aParent = $this->createPage('fp-violation-aaa', 0, publish: false);
        $this->createPage('fp-violation-aaa-child', $aParent->ID);

        $body = $this->fingerprint();

        $ourPaths = array_values(array_filter(
            array_column($body['data']['violations'], 'path'),
            static fn (string $path) => str_starts_with($path, '/fp-violation-')
        ));

        $this->assertSame(
            ['/fp-violation-aaa/fp-violation-aaa-child', '/fp-violation-zzz/fp-violation-zzz-child'],
            $ourPaths
        );
    }

    /**
     * Code-review regression test: N live related records sharing one
     * blocked owner page previously produced N identical-looking
     * `{className, path, blockedBy}` violation entries (the entry
     * identifies the OWNER page, not any individual record) — pure noise
     * describing the same single problem.
     */
    public function testDuplicateRelatedViolationsOnTheSameOwnerAreCollapsedToOne(): void
    {
        $parent = $this->createPage('fp-dedupe-parent', 0, publish: false);
        $child = $this->createPage('fp-dedupe-child', $parent->ID);

        $this->inDraft(function () use ($child) {
            foreach (['one', 'two'] as $suffix) {
                $record = ApiTestFingerprintRelatedObject::create([
                    'Title' => "Slide {$suffix}",
                    'PageID' => $child->ID,
                ]);
                $record->write();
                $record->publishSingle();
            }
        });

        $body = $this->fingerprint();

        $relatedViolations = array_values(array_filter(
            $body['data']['violations'],
            static fn (array $v) => $v['className'] === ApiTestFingerprintRelatedObject::class
        ));

        $this->assertCount(
            1,
            $relatedViolations,
            'two related records under the same blocked owner must collapse to one violation entry'
        );
    }

    /**
     * Code-review regression test: when the owner page itself isn't live
     * (as opposed to live-but-under-a-draft-only-ancestor), `blockedBy`
     * previously reported ONLY the owner's own path, silently dropping any
     * further non-live ancestor above it.
     */
    public function testBlockedByIncludesTheFullAncestorChainWhenTheOwnerItselfIsntLive(): void
    {
        $grandparent = $this->createPage('fp-full-chain-grandparent', 0, publish: false);
        $parent = $this->createPage('fp-full-chain-parent', $grandparent->ID, publish: false);

        $this->inDraft(function () use ($parent) {
            $record = ApiTestFingerprintRelatedObject::create([
                'Title' => 'Deep',
                'PageID' => $parent->ID,
            ]);
            $record->write();
            $record->publishSingle();
        });

        $body = $this->fingerprint();

        $violations = array_values(array_filter(
            $body['data']['violations'],
            static fn (array $v) => $v['className'] === ApiTestFingerprintRelatedObject::class
        ));

        $this->assertCount(1, $violations);
        $this->assertSame(
            ['/fp-full-chain-grandparent', '/fp-full-chain-grandparent/fp-full-chain-parent'],
            $violations[0]['blockedBy']
        );
    }

    /**
     * Code-review regression test: rows sharing one `ownerPath` sorted
     * with no secondary key, so ties preserved insertion (id) order — the
     * exact id-churn problem path-keying exists to eliminate, for the very
     * common case of multiple related records under one owner page.
     */
    public function testRelatedRecordsWithTheSameOwnerSortByExternalId(): void
    {
        $page = $this->createPage('fp-tiebreak-owner');

        $this->inDraft(function () use ($page) {
            foreach ([['B', 'ext-b'], ['A', 'ext-a']] as [$title, $extId]) {
                $record = ApiTestFingerprintRelatedObject::create([
                    'Title' => $title,
                    'PageID' => $page->ID,
                    'FixtureIdentifier' => $extId,
                ]);
                $record->write();
                $record->publishSingle();
            }
        });

        $body = $this->fingerprint();

        $ours = array_values(array_filter(
            $body['data']['related']['ApiTestFingerprintRelated']['records'],
            static fn (array $row) => $row['ownerPath'] === '/fp-tiebreak-owner'
        ));

        $this->assertSame(['ext-a', 'ext-b'], array_column($ours, 'externalId'));
    }

    /**
     * Code-review regression test: a non-versioned related class has no
     * stage concept at all — it always exists — but `isLive` previously
     * defaulted to `false` unconditionally for it, which meant the
     * reachability check (`if ($isLive) { ... }`) never ran at all for
     * that class: a non-versioned record under an unreachable page
     * reported `live: false, violations: []` instead of flagging the
     * actual problem.
     */
    public function testNonVersionedRelatedRecordIsTreatedAsLiveAndCanViolate(): void
    {
        $parent = $this->createPage('fp-nonversioned-parent', 0, publish: false);
        $owner = $this->createPage('fp-nonversioned-owner', $parent->ID);

        $record = ApiTestFingerprintNonVersionedRelatedObject::create([
            'Title' => 'Always live',
            'PageID' => $owner->ID,
        ]);
        $record->write();

        $body = $this->fingerprint();

        $rows = array_values(array_filter(
            $body['data']['related']['ApiTestFingerprintNonVersionedRelated']['records'],
            static fn (array $row) => $row['ownerPath'] === '/fp-nonversioned-parent/fp-nonversioned-owner'
        ));

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['live'], 'a non-versioned record has no stage — it is always effectively live');

        $violations = array_values(array_filter(
            $body['data']['violations'],
            static fn (array $v) => $v['className'] === ApiTestFingerprintNonVersionedRelatedObject::class
        ));
        $this->assertCount(1, $violations, 'reachability must still be asserted for a non-versioned related class');
    }

    /**
     * Code-review regression test: `getField()` on an unknown column
     * returns `null` rather than throwing, so a misconfigured owner
     * column (a typo, or the has_one RELATION name instead of its real FK
     * column — e.g. "Page" instead of "PageID") previously proceeded
     * silently: every record either looked like a broken FK (wrong column
     * name) or was silently mis-attributed to whatever page has id 1 (the
     * relation-name mistake — `(int)` casting the resolved DataObject).
     */
    public function testMisconfiguredOwnerColumnIsSkippedNotSilentlyWrong(): void
    {
        Config::modify()->set(FingerprintService::class, 'related_classes', [
            'ApiTestFingerprintRelated' => 'Page',
        ]);

        $body = $this->fingerprint();

        $this->assertContains('ApiTestFingerprintRelated', $body['meta']['skipped']);
        $this->assertArrayNotHasKey('ApiTestFingerprintRelated', $body['data']['related']);
    }
}
