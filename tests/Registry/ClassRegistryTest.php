<?php

namespace Dynamic\ContentApi\Tests\Registry;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Tests\Stub\ApiTestDiscoveryChild;
use Dynamic\ContentApi\Tests\Stub\ApiTestDiscoveryGrandchild;
use Dynamic\ContentApi\Tests\Stub\ApiTestDiscoveryMiddle;
use Dynamic\ContentApi\Tests\Stub\ApiTestDiscoveryNonDataObjectAbstract;
use Dynamic\ContentApi\Tests\Stub\ApiTestDiscoveryNonDataObjectConcrete;
use Dynamic\ContentApi\Tests\Stub\ApiTestDiscoveryRoot;
use Dynamic\ContentApi\Tests\Stub\ApiTestExtraSourceAccessExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * discovery_roots is opt-in and off by default (empty discovery_roots),
 * so every test here sets it explicitly rather than relying on project
 * config — these tests must pass whether or not any real project config
 * happens to enable discovery.
 */
class ClassRegistryTest extends SapphireTest
{
    protected function tearDown(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', []);
        Config::modify()->set(ClassRegistry::class, 'discovery_exclude', []);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'off');

        parent::tearDown();
    }

    public function testDiscoveryIsOffByDefault(): void
    {
        $registry = ClassRegistry::singleton();

        $this->assertArrayNotHasKey('ApiTestDiscoveryChild', $registry->allExposed());
        $this->assertSame([], $registry->accessVerbs(ApiTestDiscoveryChild::class));
    }

    public function testDiscoveryRootExposesSubclassesNotItself(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();
        $exposed = $registry->allExposed();

        $this->assertArrayHasKey('ApiTestDiscoveryChild', $exposed);
        $this->assertSame(ApiTestDiscoveryChild::class, $exposed['ApiTestDiscoveryChild']['class']);
        $this->assertSame(['read'], $exposed['ApiTestDiscoveryChild']['verbs']);

        // The abstract-ish root itself isn't exposed — discovery walks
        // concrete subclasses (includeBaseClass: false), matching the
        // intended use (point at BaseElement/SiteTree, not a leaf).
        $this->assertArrayNotHasKey('ApiTestDiscoveryRoot', $exposed);

        // Regression: accessVerbs() called directly on the root class (as
        // AssetHandler::governingAssetClass()/RecordWriter::update() do via
        // get_class($record), bypassing resolve()) must agree with
        // allExposed() that the root itself was never discovered — is_a()
        // used to match the root against itself, not just true subclasses.
        $this->assertSame([], $registry->accessVerbs(ApiTestDiscoveryRoot::class));
    }

    public function testDiscoveryWritePolicyOffExcludesDiscoveredClassesEntirely(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'off');

        $registry = ClassRegistry::singleton();

        $this->assertSame([], $registry->accessVerbs(ApiTestDiscoveryChild::class));
        $this->assertArrayNotHasKey('ApiTestDiscoveryChild', $registry->allExposed());

        // 'off' means the class isn't merely zero-verb, it's excluded from
        // the map outright — resolve() must still throw UNKNOWN_CLASS for
        // it, same as before discovery existed for a class nobody mapped.
        $this->expectException(ApiError::class);
        $registry->resolve('ApiTestDiscoveryChild');
    }

    public function testExplicitApiAccessWinsOverDiscoveryFallback(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');
        Config::modify()->set(ApiTestDiscoveryChild::class, 'api_access', 'GET,POST');

        $registry = ClassRegistry::singleton();

        $this->assertSame(['read', 'create'], $registry->accessVerbs(ApiTestDiscoveryChild::class));
    }

    /**
     * Regression: an explicit falsy content_api_access (false, or '') must
     * still be treated as a deliberate deny, not conflated with "never
     * configured" — accessVerbs() distinguishes the two via Config::exists(),
     * not a plain falsy check, specifically so this can't silently fall
     * through to the discovery read grant.
     *
     * This must use content_api_access, not api_access — the latter can't
     * make the same distinction because SilverStripe\ORM\DataObject itself
     * declares a built-in `private static $api_access = false`, so
     * Config::exists() is always true for that key on every DataObject
     * subclass (see accessVerbs()'s docblock). An explicit `api_access:
     * false` is therefore indistinguishable from DataObject's own untouched
     * default and can't reliably override discovery — content_api_access or
     * discovery_exclude are the two ways to actually opt a class out.
     */
    public function testExplicitFalsyContentApiAccessIsNotOverriddenByDiscovery(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');
        Config::modify()->set(ApiTestDiscoveryChild::class, 'content_api_access', false);

        $registry = ClassRegistry::singleton();

        $this->assertSame([], $registry->accessVerbs(ApiTestDiscoveryChild::class));
    }

    /**
     * Regression: api_access collides with DataObject's own built-in
     * config property of the same name — Config::get() must still resolve
     * to *this class's* explicitly-set value, not silently prefer/confuse
     * it with the framework default, when a project does set a real value.
     */
    public function testApiAccessStillWorksDespiteTheDataObjectCollision(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');
        Config::modify()->set(ApiTestDiscoveryChild::class, 'api_access', 'GET,PUT');

        $registry = ClassRegistry::singleton();

        $this->assertSame(['read', 'update'], $registry->accessVerbs(ApiTestDiscoveryChild::class));
    }

    public function testDiscoveryExcludeRemovesAClassFromDiscovery(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_exclude', [ApiTestDiscoveryChild::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();

        $this->assertArrayNotHasKey('ApiTestDiscoveryChild', $registry->allExposed());
        $this->assertSame([], $registry->accessVerbs(ApiTestDiscoveryChild::class));
    }

    /**
     * Excluding a parent class must also exclude its subclasses — the same
     * subclass-expansion the mandatory denylist relies on, exercised here
     * against a real subclass (Member has none of its own in this suite).
     */
    public function testDiscoveryExcludeAlsoExcludesSubclassesOfTheExcludedClass(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_exclude', [ApiTestDiscoveryMiddle::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();
        $exposed = $registry->allExposed();

        $this->assertArrayHasKey('ApiTestDiscoveryChild', $exposed, 'unrelated sibling must still be discovered');
        $this->assertArrayNotHasKey('ApiTestDiscoveryGrandchild', $exposed);
    }

    /**
     * The mandatory denylist can't be relaxed away by project config —
     * pointing discovery_roots directly at a denylisted class must still
     * exclude it, not just its subclasses.
     */
    public function testMandatoryDenylistCannotBeOverridden(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [Member::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();

        $this->assertSame([], $registry->accessVerbs(Member::class));
        $this->assertArrayNotHasKey('Member', $registry->allExposed());
    }

    /**
     * Discovery must only ever map concrete, instantiable classes.
     * ClassInfo::subclassesFor() alone doesn't filter out an abstract
     * intermediate class, so ClassRegistry::discoveredModels() checks
     * ReflectionClass::isInstantiable() itself.
     *
     * Exercised via a plain PHP class hierarchy (not DataObject-backed):
     * SapphireTest's temp-DB table builder
     * (SilverStripe\ORM\Connect\TableBuilder) instantiates every discovered
     * DataObject subclass regardless of test mode and fatals on a literal
     * `abstract class` ("Cannot instantiate abstract class ..."), so a real
     * abstract DataObject fixture can't be used here — discoveredModels()'s
     * filtering logic itself doesn't require DataObject, only that
     * ClassInfo::subclassesFor() finds the class and ReflectionClass reports
     * whether it's instantiable, both true for a plain class too.
     */
    public function testAbstractIntermediateClassIsNeverDiscovered(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryNonDataObjectAbstract::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('discoveredModels');
        $method->setAccessible(true);
        $discovered = $method->invoke($registry);

        $this->assertArrayNotHasKey('ApiTestDiscoveryNonDataObjectAbstract', $discovered);
        $this->assertArrayHasKey('ApiTestDiscoveryNonDataObjectConcrete', $discovered);
        $this->assertSame(
            ApiTestDiscoveryNonDataObjectConcrete::class,
            $discovered['ApiTestDiscoveryNonDataObjectConcrete']
        );
    }

    /**
     * Two discovery_roots configured together must both contribute, not
     * have one silently override the other.
     */
    public function testMultipleDiscoveryRootsBothContribute(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [
            ApiTestDiscoveryRoot::class,
            Member::class, // fully denylisted — contributes nothing, proves roots are independent
        ]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();
        $exposed = $registry->allExposed();

        $this->assertArrayHasKey('ApiTestDiscoveryChild', $exposed);
        $this->assertArrayHasKey('ApiTestDiscoveryGrandchild', $exposed);
        $this->assertArrayNotHasKey('Member', $exposed);
    }

    /**
     * A manual `models` mapping always wins over a discovered entry sharing
     * the same short ref — including redirecting the ref to a DIFFERENT
     * class than the one discovery would have picked.
     */
    public function testManualModelsMappingWinsOverADiscoveredRefCollision(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');
        Config::modify()->set(ClassRegistry::class, 'models', ['ApiTestDiscoveryChild' => Member::class]);

        $registry = ClassRegistry::singleton();

        $this->assertSame(Member::class, $registry->resolve('ApiTestDiscoveryChild'));
    }

    public function testDiscoveredClassIsResolvableByShortRef(): void
    {
        Config::modify()->set(ClassRegistry::class, 'discovery_roots', [ApiTestDiscoveryRoot::class]);
        Config::modify()->set(ClassRegistry::class, 'discovery_write_policy', 'read');

        $registry = ClassRegistry::singleton();

        $this->assertSame(ApiTestDiscoveryChild::class, $registry->resolve('ApiTestDiscoveryChild'));
        $this->assertSame('ApiTestDiscoveryChild', $registry->refFor(ApiTestDiscoveryChild::class));
    }

    /**
     * refFor()'s ancestry walk must resolve a discovered class that was
     * never itself a direct map key by climbing to a registered ancestor —
     * exercised here via a manual mapping on the intermediate class, since
     * the grandchild itself isn't directly keyed.
     */
    public function testRefForWalksAncestryToARegisteredAncestor(): void
    {
        Config::modify()->set(ClassRegistry::class, 'models', ['DiscoveryAncestor' => ApiTestDiscoveryMiddle::class]);

        $registry = ClassRegistry::singleton();

        $this->assertSame('DiscoveryAncestor', $registry->refFor(ApiTestDiscoveryGrandchild::class));
    }

    /**
     * Regression: accessVerbs() is deliberately inherited — a subclass with
     * no content_api_access of its own still reports its ancestor's verbs.
     * Pinned here specifically so it reads as a documented, intentional
     * contrast against ownAccessVerbs() below, not an accidental gap.
     */
    public function testAccessVerbsIsInheritedByASubclass(): void
    {
        Config::modify()->set(ApiTestDiscoveryRoot::class, 'content_api_access', 'GET,POST,PUT,DELETE,action');

        $registry = ClassRegistry::singleton();

        $this->assertSame(ClassRegistry::VERBS, $registry->accessVerbs(ApiTestDiscoveryChild::class));
    }

    /**
     * ownAccessVerbs() is the uninherited counterpart ContentApiGrantExtension
     * relies on: a class declaring content_api_access itself reports its own
     * verbs; a subclass that inherits the same value from an ancestor (see
     * the accessVerbs() test above) must report nothing at all here — that's
     * the whole point of the distinction.
     */
    public function testOwnAccessVerbsIgnoresInheritedContentApiAccess(): void
    {
        Config::modify()->set(ApiTestDiscoveryRoot::class, 'content_api_access', 'GET,POST,PUT,DELETE,action');

        $registry = ClassRegistry::singleton();

        $this->assertSame(ClassRegistry::VERBS, $registry->ownAccessVerbs(ApiTestDiscoveryRoot::class));
        $this->assertSame([], $registry->ownAccessVerbs(ApiTestDiscoveryChild::class));
    }

    public function testOwnAccessVerbsFallsBackToApiAccessWhenSetOnTheSameClass(): void
    {
        Config::modify()->set(ApiTestDiscoveryRoot::class, 'api_access', 'GET,PUT');

        $registry = ClassRegistry::singleton();

        $this->assertSame(['read', 'update'], $registry->ownAccessVerbs(ApiTestDiscoveryRoot::class));
    }

    public function testOwnAccessVerbsTreatsAnExplicitFalsyValueAsDeclaredButEmpty(): void
    {
        Config::modify()->set(ApiTestDiscoveryRoot::class, 'content_api_access', false);

        $registry = ClassRegistry::singleton();

        $this->assertSame([], $registry->ownAccessVerbs(ApiTestDiscoveryRoot::class));
    }

    public function testOwnAccessVerbsIsEmptyWhenNothingIsSetAtAll(): void
    {
        $registry = ClassRegistry::singleton();

        $this->assertSame([], $registry->ownAccessVerbs(ApiTestDiscoveryRoot::class));
    }

    /**
     * ContentApiGrantExtension's whole safety model depends on
     * ownAccessVerbs() answering only for a class's own LITERAL
     * declaration — not one contributed by an extension applied to it.
     * Without Config::EXCLUDE_EXTRA_SOURCES, any extension carrying its own
     * `content_api_access` static (e.g. one applied for an unrelated
     * reason) would silently opt a class into the grant, regardless of
     * whether the class's own class body ever mentioned content_api_access
     * at all.
     */
    public function testOwnAccessVerbsExcludesValueContributedByAnExtension(): void
    {
        Config::modify()->set(
            ApiTestDiscoveryRoot::class,
            'extensions',
            [ApiTestExtraSourceAccessExtension::class]
        );

        $registry = ClassRegistry::singleton();

        $this->assertSame([], $registry->ownAccessVerbs(ApiTestDiscoveryRoot::class));
    }
}
