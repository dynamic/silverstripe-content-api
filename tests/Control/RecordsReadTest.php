<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Control\Handlers\RecordsHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Verify\VersionCache;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class RecordsReadTest extends ContentApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('apiUser');

        // `path:` addressing is SiteTree-only (#121), so these cases address
        // SiteTree itself — the same registration PageActionsTest does.
        Config::modify()->set(SiteTree::class, 'api_access', 'read');
        Config::modify()->merge(
            \Dynamic\ContentApi\Registry\ClassRegistry::class,
            'models',
            ['SiteTree' => SiteTree::class]
        );
    }

    /**
     * The fixture pages are draft-only, and `Versioned::canViewVersioned()`
     * vetoes a draft read whose stages diverge unless the member holds
     * `non_live_permissions` — the same `VIEW_DRAFT_CONTENT` the README tells
     * a host to grant alongside CONTENT_API_ACCESS. Scoped to the cases that
     * read a page, so the rest of this file keeps its original conditions.
     */
    private function allowDraftPageReads(): void
    {
        $apiGroup = $this->objFromFixture(Group::class, 'apiGroup');
        Permission::grant((int) $apiGroup->ID, 'VIEW_DRAFT_CONTENT');
        Permission::reset();
    }

    public function testListReturnsRecords(): void
    {
        $response = $this->apiGet('records/ApiTest', $this->token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // canView filtering runs before total/pagination are computed (#20),
        // so total reflects only the 5 visible records, not the raw 6.
        $this->assertSame(5, $body['meta']['total']);
        $this->assertCount(5, $body['data']);

        $first = $body['data'][0];
        $this->assertSame('ApiTest', $first['classRef']);
        $this->assertArrayHasKey('Title', $first['fields']);
        $this->assertArrayNotHasKey('FixtureIdentifier', $first['fields']);
    }

    public function testListFilters(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest?Title=Beta', $this->token));
        $this->assertCount(1, $body['data']);
        $this->assertSame('Beta', $body['data'][0]['fields']['Title']);

        $body = $this->decode($this->apiGet('records/ApiTest?Title__PartialMatch=Beta', $this->token));
        $this->assertCount(2, $body['data']);

        $body = $this->decode($this->apiGet('records/ApiTest?Rank__GreaterThan=1&Rank__LessThan=99', $this->token));
        $this->assertCount(2, $body['data']);
    }

    public function testListRejectsUnknownFilterField(): void
    {
        $response = $this->apiGet('records/ApiTest?Bogus=1', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_FIELD', 422);
    }

    public function testListRejectsUnknownModifier(): void
    {
        $response = $this->apiGet('records/ApiTest?Title__DropTable=1', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testListSortAndPagination(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest?sort=-Rank&limit=2', $this->token));

        // canView filtering runs before pagination (#20): Secret (Rank 99)
        // is excluded from the visible set entirely, so a full 2-record
        // page comes back — [Beta Prime, Beta] — instead of a short page
        // with Secret silently occupying a slot and then vanishing.
        $this->assertCount(2, $body['data']);
        $this->assertSame(2, $body['meta']['limit']);
        $this->assertSame(5, $body['meta']['total']);
        $this->assertSame('Beta Prime', $body['data'][0]['fields']['Title']);
        $this->assertSame('Beta', $body['data'][1]['fields']['Title']);

        $body = $this->decode($this->apiGet('records/ApiTest?sort=Rank&limit=2&offset=4', $this->token));
        $this->assertSame(4, $body['meta']['offset']);
    }

    public function testReadOneById(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $this->assertSame('Alpha', $body['data']['fields']['Title']);
        $this->assertSame('alpha', $body['data']['externalId']);
        $this->assertArrayHasKey('Buddy', $body['data']['relations']);
    }

    public function testReadOneByExternalId(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest/ext:alpha', $this->token));

        $this->assertSame('Alpha', $body['data']['fields']['Title']);
    }

    public function testAmbiguousExternalIdConflicts(): void
    {
        $response = $this->apiGet('records/ApiTest/ext:dup', $this->token);

        $this->assertErrorCode($response, 'MULTIPLE_MATCHES', 409);
    }

    public function testMissingExternalIdIs404(): void
    {
        $response = $this->apiGet('records/ApiTest/ext:nope', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testReadOneByPath(): void
    {
        $this->allowDraftPageReads();

        $body = $this->decode($this->apiGet('records/SiteTree/path:about', $this->token));

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $this->assertSame('About', $body['data']['fields']['Title']);
        $this->assertSame((int) $about->ID, $body['data']['id']);
    }

    /**
     * `$ID` matches a single route segment, so a nested path reaches the
     * handler as one `%2F`-encoded segment and is decoded back into slashes.
     */
    public function testReadOneByNestedPath(): void
    {
        $this->allowDraftPageReads();

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');

        $child = SiteTree::create([
            'Title' => 'Our Team',
            'URLSegment' => 'our-team',
            'ParentID' => (int) $about->ID,
        ]);
        $child->write();

        $body = $this->decode($this->apiGet('records/SiteTree/path:about%2Four-team', $this->token));

        $this->assertSame('Our Team', $body['data']['fields']['Title']);
        $this->assertSame((int) $child->ID, $body['data']['id']);
    }

    /**
     * #121: `get_by_link()`'s unique-URLSegment fallback returns as soon as it
     * matches a segment anywhere in the tree, without walking the rest of the
     * path — so `our-team/former-child` resolves to `about/our-team` and the
     * trailing segment is silently dropped. The front end can treat leftovers
     * as a controller action; an id that also addresses PATCH and publish
     * cannot, or a stale path acts on a page the caller never named.
     *
     * Note this needs a *nested* first segment. Under a root page
     * (`about/not-a-child`) the root lookup succeeds, so the fallback is never
     * reached and `get_by_link()` returns null on its own — that case 404s with
     * or without the guard and proves nothing about it.
     */
    public function testTrailingSegmentsAfterANestedPageAre404(): void
    {
        $this->allowDraftPageReads();

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');
        $child = SiteTree::create([
            'Title' => 'Our Team',
            'URLSegment' => 'our-team',
            'ParentID' => (int) $about->ID,
        ]);
        $child->write();

        // The page itself is addressable by its full path, so the 404 below is
        // about the trailing segment and not a page that never existed.
        $full = $this->apiGet('records/SiteTree/path:about%2Four-team', $this->token);
        $this->assertSame(200, $full->getStatusCode(), (string)$full->getBody());
        $this->assertSame((int) $child->ID, $this->decode($full)['data']['id']);

        $response = $this->apiGet('records/SiteTree/path:our-team%2Fformer-child', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    /**
     * #121: the other fallback the guard covers. With `nested_urls` off,
     * `get_by_link()` matches the first segment and returns without looking at
     * the remaining ones, so `about/anything` hands back `about` — the
     * stale-path-acts-on-the-parent case in its literal form. A plain
     * single-segment path still has to resolve, or this would just be a test
     * that nested_urls off breaks everything.
     */
    public function testTrailingSegmentsAre404WithNestedUrlsOff(): void
    {
        $this->allowDraftPageReads();

        Config::modify()->set(SiteTree::class, 'nested_urls', false);
        SiteTree::reset();

        $plain = $this->apiGet('records/SiteTree/path:about', $this->token);
        $this->assertSame(200, $plain->getStatusCode(), (string)$plain->getBody());

        $response = $this->apiGet('records/SiteTree/path:about%2Fnot-a-child', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    /**
     * #121: the same fallback means a nested page must be addressed by its
     * full path and not by its bare segment, or two pages at different depths
     * would share one address.
     */
    public function testANestedPageIsNotAddressableByItsBareSegment(): void
    {
        $this->allowDraftPageReads();

        $about = $this->objFromFixture(SiteTree::class, 'aboutPage');
        $child = SiteTree::create([
            'Title' => 'Our Team',
            'URLSegment' => 'our-team',
            'ParentID' => (int) $about->ID,
        ]);
        $child->write();

        $bare = $this->apiGet('records/SiteTree/path:our-team', $this->token);
        $this->assertErrorCode($bare, 'NOT_FOUND', 404);

        $full = $this->apiGet('records/SiteTree/path:about%2Four-team', $this->token);
        $this->assertSame(200, $full->getStatusCode(), (string)$full->getBody());
        $this->assertSame((int) $child->ID, $this->decode($full)['data']['id']);
    }

    /**
     * A leading slash must not let `Director::makeRelative()` chew a prefix off the
     * path. It strips a `Director::baseURL()` prefix by plain case-insensitive prefix
     * match with no check that the match ends at a `/`, so on a site installed in a
     * subfolder (`/en/`) the path `/enquiries` became `quiries` and resolved a
     * different page. The exact-path guard could not catch it, because it compared
     * against the already-shortened link — which matters because the same `$ID`
     * addresses `PATCH`, `publish` and `archive`. Paths are site-root relative, so no
     * base prefix is stripped here at all; `get_by_link()` still relativises, on a
     * value that no longer starts with a slash to match against.
     */
    public function testALeadingSlashDoesNotResolveAPrefixedPageNameOnASubfolderInstall(): void
    {
        $this->allowDraftPageReads();
        Config::modify()->set(Director::class, 'alternate_base_url', '/en/');

        $enquiries = SiteTree::create(['Title' => 'Enquiries', 'URLSegment' => 'enquiries']);
        $enquiries->write();
        // The page `makeRelative()` used to fall back onto: `/enquiries` minus `/en`.
        $quiries = SiteTree::create(['Title' => 'Quiries', 'URLSegment' => 'quiries']);
        $quiries->write();

        // The caller means `/enquiries`. Answering with `quiries` is the bug.
        $body = $this->decode($this->apiGet('records/SiteTree/path:%2Fenquiries', $this->token));
        $this->assertSame(
            (int) $enquiries->ID,
            $body['data']['id'],
            'A leading slash resolved a different page: makeRelative() stripped the "/en" base prefix'
        );

        // The page it used to land on is still addressed by its own name.
        $body = $this->decode($this->apiGet('records/SiteTree/path:quiries', $this->token));
        $this->assertSame((int) $quiries->ID, $body['data']['id']);

        // Surrounding slashes are still ignored, which is what the docs promise.
        $body = $this->decode($this->apiGet('records/SiteTree/path:%2Fenquiries%2F', $this->token));
        $this->assertSame((int) $enquiries->ID, $body['data']['id']);
    }

    public function testMissingPathIs404(): void
    {
        $response = $this->apiGet('records/SiteTree/path:no-such-page', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    /**
     * A path that resolves to a page which is not the addressed class (or a
     * subclass of it) is a miss, not a type error — the same shape as a
     * numeric id pointing at another class.
     */
    public function testPathResolvingToAnotherClassIs404(): void
    {
        $response = $this->apiGet('records/ApiTestPage/path:about', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testPathAddressingOnANonSiteTreeClassIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTest/path:about', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * `SiteTree::get_by_link('')` falls back to the home page; an explicitly
     * empty path is refused instead, so `path:` can never silently read `/`.
     */
    public function testEmptyPathIsRejected(): void
    {
        $response = $this->apiGet('records/SiteTree/path:', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * The same refusal expressed as an encoded root path, so the empty check
     * cannot be stepped around by sending `path:%2F` instead of `path:`.
     */
    public function testARootPathIsRejectedToo(): void
    {
        $response = $this->apiGet('records/SiteTree/path:%2F', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * Refusing `/` does not mean the home page is unaddressable: it is reached
     * by its own segment. `RelativeLink()` returns the site root for a
     * root-level homepage, so without `fullLink()` restoring the segment the
     * exact-path guard compares an empty string against `home` and 404s the
     * one page every site has — which is also the page a caller most
     * obviously reaches for by path.
     */
    public function testTheHomePageIsAddressedByItsOwnSegment(): void
    {
        $this->allowDraftPageReads();

        $response = $this->apiGet('records/SiteTree/path:home', $this->token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);
        $home = $this->objFromFixture(SiteTree::class, 'homePage');

        $this->assertSame('Home', $body['data']['fields']['Title']);
        $this->assertSame((int) $home->ID, $body['data']['id']);
    }

    public function testPathAddressingFollowsTheStage(): void
    {
        $this->allowDraftPageReads();

        $page = SiteTree::create([
            'Title' => 'Draft Only Page',
            'URLSegment' => 'draft-only-page',
        ]);
        $page->write();

        $body = $this->decode($this->apiGet('records/SiteTree/path:draft-only-page', $this->token));
        $this->assertSame('Draft Only Page', $body['data']['fields']['Title']);

        // Live misses the unpublished page.
        $response = $this->apiGet('records/SiteTree/path:draft-only-page?_stage=live', $this->token);
        $this->assertErrorCode($response, 'NOT_FOUND', 404);

        // After publish, live finds it.
        $page->publishSingle();

        $body = $this->decode($this->apiGet('records/SiteTree/path:draft-only-page?_stage=live', $this->token));
        $this->assertSame('Draft Only Page', $body['data']['fields']['Title']);
    }

    public function testMissingIdIs404(): void
    {
        $response = $this->apiGet('records/ApiTest/999999', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testMalformedIdIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTest/abc', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testUnknownClassRefIs404(): void
    {
        $response = $this->apiGet('records/Nope', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_CLASS', 404);
    }

    public function testMemberWithoutApiPermissionIsForbidden(): void
    {
        $token = $this->mintTokenFor('noAccessUser');

        $response = $this->apiGet('records/ApiTest', $token);

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testClassWithoutApiAccessIsForbidden(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);

        $response = $this->apiGet('records/ApiTest', $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testContentApiAccessOverridesApiAccess(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);
        Config::modify()->set(ApiTestObject::class, 'content_api_access', 'read');

        $response = $this->apiGet('records/ApiTest', $this->token);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRecordCanViewIsEnforcedOnReadOne(): void
    {
        $secret = $this->objFromFixture(ApiTestObject::class, 'secret');

        $response = $this->apiGet("records/ApiTest/{$secret->ID}", $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
    }

    public function testVersionedStageReads(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        // Draft (default) sees the record and reports stage state.
        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}", $this->token));
        $this->assertTrue($body['data']['stage']['draft']);
        $this->assertFalse($body['data']['stage']['live']);
        $this->assertSame('draft', $body['meta']['stage']);

        // Live misses the draft-only record.
        $response = $this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->token);
        $this->assertErrorCode($response, 'NOT_FOUND', 404);

        // After publish, live sees it.
        $record->publishSingle();
        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->token));
        $this->assertTrue($body['data']['stage']['live']);
    }

    public function testInvalidStageIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTestVersioned?_stage=nope', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * #244: malformed paging values are refused, not coerced.
     */
    public function testMalformedPagingIsRejected(): void
    {
        foreach (['limit=abc', 'limit=0', 'limit=-5', 'limit=1.9', 'limit=5abc', 'offset=abc', 'offset=-1'] as $query) {
            $response = $this->apiGet('records/ApiTest?' . $query, $this->token);
            $this->assertSame(400, $response->getStatusCode(), $query);
            $this->assertSame('PAYLOAD_INVALID', $this->decode($response)['error']['code'], $query);
        }

        $max = (int) Config::inst()->get(RecordsHandler::class, 'max_limit');
        $body = $this->decode($this->apiGet('records/ApiTest?limit=' . ($max + 1) . '&offset=', $this->token));
        $this->assertSame($max, $body['meta']['limit'], 'an over-large limit is still clamped');
        $this->assertSame(0, $body['meta']['offset']);
    }

    /**
     * #244: an empty filter value filters on the empty string.
     */
    public function testAnEmptyFilterValueMatchesTheEmptyString(): void
    {
        $this->assertCount(0, $this->decode($this->apiGet('records/ApiTest?Title=', $this->token))['data']);

        $blank = ApiTestObject::create(['Title' => '']);
        $blank->write();

        $body = $this->decode($this->apiGet('records/ApiTest?Title=', $this->token));
        $this->assertSame([(int) $blank->ID], array_column($body['data'], 'id'));

        $body = $this->decode($this->apiGet('records/ApiTest?Title__not=', $this->token));
        $this->assertNotContains((int) $blank->ID, array_column($body['data'], 'id'));
        $this->assertCount(5, $body['data']);

        $this->assertErrorCode($this->apiGet('records/ApiTest?Rank=', $this->token), 'PAYLOAD_INVALID', 400);
    }

    /**
     * #244: a draft list primes the version-number cache instead of
     * looking up each row's version, so the query count does not grow
     * with the number of rows the list visits.
     */
    public function testADraftListsQueryCountDoesNotGrowWithItsRows(): void
    {
        // A non-admin token, so canView() runs Versioned's stage check per
        // row rather than an ADMIN short-circuit.
        $token = $this->token;
        $count = function () use ($token): int {
            DataObject::flush_and_destroy_cache();
            $before = $this->questions();
            $response = $this->apiGet('records/ApiTestPage?limit=1', $token);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

            return $this->questions() - $before;
        };

        $this->pages(3);
        $few = $count();
        $this->pages(12);
        $many = $count();

        $this->assertLessThan(5, $many - $few, "{$few} queries for 3 rows, {$many} for 15");
    }

    /**
     * #244: priming batches its ids, so a list larger than one batch reads
     * the same as a small one.
     */
    public function testPrimingBatchesItsIds(): void
    {
        $ids = range(1, 1201);
        VersionCache::prime(ApiTestPage::class, $ids);

        $this->assertNull(Versioned::get_versionnumber_by_stage(ApiTestPage::class, Versioned::LIVE, 1201));
    }

    /**
     * Half published, half draft-only: a draft-only row has no live
     * version, the case the older version cache leaves uncached.
     */
    private function pages(int $count): void
    {
        Versioned::withVersionedMode(function () use ($count) {
            Versioned::set_stage(Versioned::DRAFT);

            for ($i = 0; $i < $count; $i++) {
                $page = ApiTestPage::create(['Title' => 'Listed ' . uniqid()]);
                $page->write();

                if ($i % 2 === 0) {
                    $page->publishSingle();
                }
            }
        });
    }

    /**
     * Statements this connection has run, per MySQL's own counter.
     */
    private function questions(): int
    {
        return (int) DB::query("SHOW SESSION STATUS LIKE 'Questions'")->record()['Value'];
    }
}
