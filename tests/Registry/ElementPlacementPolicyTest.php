<?php

namespace Dynamic\ContentApi\Tests\Registry;

use DNADesign\Elemental\Models\ElementContent;
use Dynamic\ContentApi\Registry\ElementPlacementPolicy;
use Dynamic\ContentApi\Tests\ResetsElementalTypesCacheTrait;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Direct coverage for #64's `ElementPlacementPolicy::isAllowedOnPage()` —
 * its three branches (not Elemental-enabled, allowed, disallowed) are
 * otherwise only exercised indirectly through HTTP-level composition/batch
 * tests, which conflate this class's own logic with `RecordWriter`'s
 * placement-changed gating and the write pipeline around it.
 */
class ElementPlacementPolicyTest extends SapphireTest
{
    use ResetsElementalTypesCacheTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // getElementalTypes() gates every candidate through the element's
        // own canCreate() (Permission::check('CMS_ACCESS', ...)) — without
        // a logged-in member, ApiTestElement would be excluded regardless
        // of allowed_elements/disallowed_elements, making the "allowed"
        // case indistinguishable from the "disallowed" one.
        $this->logInWithPermission('ADMIN');
    }

    protected function tearDown(): void
    {
        // getElementalTypes() caches per page class name in a static that
        // Config::modify()'s automatic rollback doesn't touch.
        static::resetElementalTypesCache();

        parent::tearDown();
    }

    public function testAPageWithoutElementalSupportIsNotBlocked(): void
    {
        $policy = ElementPlacementPolicy::create();
        $page = ApiTestPage::create();

        $this->assertTrue(
            $policy->isAllowedOnPage(ApiTestElement::class, $page),
            'a page type with no getElementalTypes() method has nothing to enforce'
        );
    }

    public function testAnElementNotInDisallowedListIsAllowed(): void
    {
        $policy = ElementPlacementPolicy::create();
        $page = ApiTestBlockPage::create();

        $this->assertTrue($policy->isAllowedOnPage(ApiTestElement::class, $page));
    }

    public function testAnElementInTheDisallowedListIsNotAllowed(): void
    {
        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $policy = ElementPlacementPolicy::create();
        $page = ApiTestBlockPage::create();

        $this->assertFalse($policy->isAllowedOnPage(ApiTestElement::class, $page));
    }

    /**
     * The other half of the config this class exists to enforce —
     * `allowed_elements` is an explicit allowlist rather than a denylist;
     * an element not named in it is excluded even without ever being
     * mentioned in `disallowed_elements`.
     */
    public function testAnElementNotInAnExplicitAllowedListIsNotAllowed(): void
    {
        Config::modify()->set(ApiTestBlockPage::class, 'allowed_elements', [ElementContent::class]);
        static::resetElementalTypesCache();

        $policy = ElementPlacementPolicy::create();
        $page = ApiTestBlockPage::create();

        $this->assertFalse($policy->isAllowedOnPage(ApiTestElement::class, $page));
    }
}
