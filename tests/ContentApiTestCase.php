<?php

namespace Dynamic\ContentApi\Tests;

use Colymba\RESTfulAPI\Authenticators\TokenAuthenticator as ColymbaTokenAuthenticator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDeprecatingObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateLeafObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateRootObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateUnversionedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestElementItem;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintNonVersionedRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedDeniedSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRestrictedRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestForceUnpublishPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestHierarchyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestMultiRelationalPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedAssetOwnerObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedGrandchildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedPageObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnsCycleObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestPlainChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestSideEffectLog;
use Dynamic\ContentApi\Tests\Stub\ApiTestSideEffectObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Tests\Stub\ApiTestTemplateModel;
use Dynamic\ContentApi\Tests\Stub\ApiTestThroughJoin;
use Dynamic\ContentApi\Tests\Stub\ApiTestUnversionedOwnedWrapperObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Member;

/**
 * Shared plumbing for content API functional tests: fixture, registry
 * config and token helpers.
 */
abstract class ContentApiTestCase extends FunctionalTest
{
    use ResetsElementalTypesCacheTrait;

    // Resolved relative to the concrete test class (tests/Control/).
    protected static $fixture_file = '../fixtures/api-test.yml';

    protected static $extra_dataobjects = [
        ApiTestObject::class,
        ApiTestChildObject::class,
        ApiTestTag::class,
        ApiTestThroughJoin::class,
        ApiTestVersionedObject::class,
        ApiTestPage::class,
        ApiTestBlockPage::class,
        ApiTestElement::class,
        ApiTestElementItem::class,
        ApiTestPlainChildObject::class,
        ApiTestCascadeObject::class,
        ApiTestPolyObject::class,
        ApiTestMultiRelationalPolyObject::class,
        ApiTestDeprecatingObject::class,
        ApiTestOwnedParentObject::class,
        ApiTestOwnedParentSubclassObject::class,
        ApiTestOwnedChildObject::class,
        ApiTestOwnedChildSubclassObject::class,
        ApiTestOwnedGrandchildObject::class,
        ApiTestOwnedAssetOwnerObject::class,
        ApiTestOwnedPageObject::class,
        ApiTestOwnsCycleObject::class,
        ApiTestUnversionedOwnedWrapperObject::class,
        ApiTestFingerprintRelatedObject::class,
        ApiTestFingerprintNonVersionedRelatedObject::class,
        ApiTestFingerprintRestrictedRelatedObject::class,
        ApiTestFingerprintRelatedDeniedSubclassObject::class,
        ApiTestHierarchyObject::class,
        ApiTestTemplateModel::class,
        ApiTestDuplicateRootObject::class,
        ApiTestDuplicateChildObject::class,
        ApiTestDuplicateLeafObject::class,
        ApiTestDuplicateUnversionedObject::class,
        ApiTestSideEffectObject::class,
        ApiTestSideEffectLog::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ClassRegistry::class, 'models', [
            'ApiTest' => ApiTestObject::class,
            'ApiTestChild' => ApiTestChildObject::class,
            'ApiTestTag' => ApiTestTag::class,
            'ApiTestVersioned' => ApiTestVersionedObject::class,
            'ApiTestPage' => ApiTestPage::class,
            'ApiTestForceUnpublishPage' => ApiTestForceUnpublishPage::class,
            'BlockPageStub' => ApiTestBlockPage::class,
            'ApiTestElement' => ApiTestElement::class,
            'ElementContent' => \DNADesign\Elemental\Models\ElementContent::class,
            'ApiTestPoly' => ApiTestPolyObject::class,
            'ApiTestMultiRelationalPoly' => ApiTestMultiRelationalPolyObject::class,
            'ApiTestDeprecating' => ApiTestDeprecatingObject::class,
            'ApiTestOwnedParent' => ApiTestOwnedParentObject::class,
            'ApiTestOwnedParentSubclass' => ApiTestOwnedParentSubclassObject::class,
            'ApiTestOwnedChild' => ApiTestOwnedChildObject::class,
            'ApiTestOwnedGrandchild' => ApiTestOwnedGrandchildObject::class,
            'ApiTestOwnedAssetOwner' => ApiTestOwnedAssetOwnerObject::class,
            'ApiTestFingerprintRelated' => ApiTestFingerprintRelatedObject::class,
            'ApiTestFingerprintNonVersionedRelated' => ApiTestFingerprintNonVersionedRelatedObject::class,
            'ApiTestFingerprintRestrictedRelated' => ApiTestFingerprintRestrictedRelatedObject::class,
            'ApiTestSideEffect' => ApiTestSideEffectObject::class,
        ]);

        // Explicit here rather than as private statics on the stubs: TestOnly
        // classes in vendored module runs aren't reliably in the config manifest.
        Config::modify()->set(ApiTestObject::class, 'api_access', true);
        Config::modify()->set(ApiTestChildObject::class, 'api_access', true);
        Config::modify()->set(ApiTestTag::class, 'api_access', true);
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', true);
        Config::modify()->set(ApiTestPage::class, 'api_access', true);
        Config::modify()->set(ApiTestBlockPage::class, 'api_access', 'read,create,update,action');
        Config::modify()->set(ApiTestElement::class, 'api_access', true);
        Config::modify()->set(ApiTestElementItem::class, 'api_access', true);
        Config::modify()->set(ApiTestPlainChildObject::class, 'api_access', true);
        Config::modify()->set(\DNADesign\Elemental\Models\ElementContent::class, 'api_access', true);
        // #119/#168: publishOwnedTree() now authorization-checks the area
        // itself (not just elements) before a composition/apply-template
        // publish — previously unchecked, since the old publish($area,
        // 'single', $member) call performed no authorization at all. A
        // real project needs the equivalent grant in its own exposure
        // config; see docs/en/08_page-compositions.md.
        Config::modify()->set(\DNADesign\Elemental\Models\ElementalArea::class, 'api_access', true);
        Config::modify()->set(ApiTestPolyObject::class, 'api_access', true);
        Config::modify()->set(ApiTestMultiRelationalPolyObject::class, 'api_access', true);
        Config::modify()->set(ApiTestDeprecatingObject::class, 'api_access', true);
        Config::modify()->set(ApiTestDeprecatingObject::class, 'api_writable_fields', ['Title', 'ReceiptTitle']);
        Config::modify()->set(ApiTestOwnedParentObject::class, 'api_access', true);
        Config::modify()->set(ApiTestOwnedParentSubclassObject::class, 'api_access', true);
        Config::modify()->set(ApiTestOwnedChildObject::class, 'api_access', true);
        Config::modify()->set(ApiTestOwnedChildSubclassObject::class, 'api_access', true);
        Config::modify()->set(ApiTestOwnedGrandchildObject::class, 'api_access', true);
        // #186: the suite runs inside a host testbed whose own exposure YAML
        // is loaded alongside this config (the SS6 testbed grants
        // SilverStripe\Assets\Image its own api_access; the SS5 testbed
        // doesn't) — a real class, unlike the ApiTest* stubs above, so a host
        // project's grant leaks in and silently decides asset-permission
        // assertions instead of the test. Pin both real asset classes here so
        // AssetHandler::governingAssetClass()'s ancestry walk starts from a
        // known state against a host's own `api_access` specifically —
        // Config::modify() always overrides a host's YAML at runtime,
        // regardless of load order. This does NOT neutralize a host that
        // instead grants via `content_api_access` (checked first, wins over
        // `api_access` unconditionally — see ClassRegistry::accessVerbs()) or
        // via `discovery_roots`/`discovery_write_policy`; neither testbed
        // does either for File/Image today, but a future one could reproduce
        // #186 again through one of those paths instead. Also note:
        // ContentApiGrantExtension reads api_access UNINHERITED for
        // record-level grants, so this pin also zeroes File's/Image's own
        // record-level canView/canEdit/canCreate grant for any test that
        // doesn't set its own — invisible today (every asset test either
        // re-grants or authenticates as adminUser), but a future test against
        // a CONTENT_API_ACCESS-only service account would 403 FORBIDDEN_RECORD
        // here, one step past the FORBIDDEN_CLASS this comment already
        // explains. Individual tests then set their own grant when they need
        // one (AssetsTest); the #119 unpublish-owns shared-asset test in
        // PublishOrchestratorTest relies on the opposite — Image having NO
        // grant at all, so the walk excludes it before authorization is ever
        // checked.
        Config::modify()->set(File::class, 'api_access', false);
        Config::modify()->set(Image::class, 'api_access', false);
        Config::modify()->set(ApiTestOwnedAssetOwnerObject::class, 'api_access', true);
        Config::modify()->set(ApiTestOwnedPageObject::class, 'api_access', 'action');
        Config::modify()->set(ApiTestOwnsCycleObject::class, 'api_access', true);
        Config::modify()->set(ApiTestFingerprintRelatedObject::class, 'api_access', true);
        Config::modify()->set(ApiTestFingerprintNonVersionedRelatedObject::class, 'api_access', true);
        Config::modify()->set(ApiTestFingerprintRestrictedRelatedObject::class, 'api_access', true);
        Config::modify()->set(ApiTestSideEffectObject::class, 'api_access', true);
        // Explicit deny on the SUBCLASS despite its parent being exposed
        // — the fixture the ApiTestFingerprintRelatedDeniedSubclassObject
        // regression test depends on.
        Config::modify()->set(ApiTestFingerprintRelatedDeniedSubclassObject::class, 'api_access', false);
    }

    /**
     * Write a colymba-style token directly onto the member (plaintext
     * ApiToken + ApiTokenExpire — the upstream storage model).
     */
    protected function mintTokenFor(string $fixtureName = 'apiUser'): string
    {
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, $fixtureName);

        $member->ApiToken = 'test-' . bin2hex(random_bytes(16));
        $member->ApiTokenExpire = time() + 3600;
        $member->write();

        return $member->ApiToken;
    }

    /**
     * A timestamp colymba treats as truly expired: its validity window is
     * `ApiTokenExpire > now - tokenLife`, so an idle token survives up to
     * 2x tokenLife — merely "< now" is NOT expired upstream.
     */
    protected function expiredTokenTimestamp(): int
    {
        $life = (int) Config::inst()->get(ColymbaTokenAuthenticator::class, 'tokenLife');

        return time() - $life - 60;
    }

    protected function apiGet(string $path, ?string $token = null): HTTPResponse
    {
        $headers = $token ? ['X-Silverstripe-Apitoken' => $token] : [];

        return $this->get("content-api/v1/{$path}", null, $headers);
    }

    protected function apiPost(string $path, array $body = [], ?string $token = null): HTTPResponse
    {
        return $this->apiSend('POST', $path, $body, $token);
    }

    protected function apiPatch(string $path, array $body = [], ?string $token = null): HTTPResponse
    {
        return $this->apiSend('PATCH', $path, $body, $token);
    }

    protected function apiDelete(string $path, ?string $token = null): HTTPResponse
    {
        return $this->apiSend('DELETE', $path, [], $token);
    }

    protected function apiSend(string $method, string $path, array $body = [], ?string $token = null): HTTPResponse
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($token) {
            $headers['X-Silverstripe-Apitoken'] = $token;
        }

        return $this->mainSession->sendRequest(
            $method,
            "content-api/v1/{$path}",
            [],
            $headers,
            null,
            json_encode($body)
        );
    }

    protected function decode(HTTPResponse $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        $this->assertIsArray(
            $decoded,
            'Response body is not valid JSON: ' . substr((string) $response->getBody(), 0, 500)
        );

        return $decoded;
    }

    protected function assertErrorCode(HTTPResponse $response, string $code, int $status): array
    {
        $body = $this->decode($response);

        $this->assertSame($status, $response->getStatusCode(), 'Unexpected HTTP status. Body: ' . $response->getBody());
        $this->assertNotNull($body['error'], 'Expected an error envelope.');
        $this->assertSame($code, $body['error']['code']);

        return $body;
    }
}
