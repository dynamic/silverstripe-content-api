<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\GrantExtensionReachabilityChecker;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantAbstractObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantExtraSourceObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantReachableObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantUnreachableObject;
use ReflectionMethod;
use SilverStripe\Dev\SapphireTest;

class GrantExtensionReachabilityCheckerTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ApiTestGrantReachableObject::class,
        ApiTestGrantUnreachableObject::class,
        ApiTestGrantExtraSourceObject::class,
    ];

    public function testFlagsAClassWhoseCanEditOverrideNeverCallsExtendedCan(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        $matches = array_filter(
            $findings,
            fn (array $finding): bool => $finding['class'] === ApiTestGrantUnreachableObject::class
                && $finding['method'] === 'canEdit'
        );

        $this->assertNotEmpty(
            $matches,
            'a class carrying ContentApiGrantExtension whose own canEdit() never calls ' .
                'extendedCan() must be flagged'
        );

        $finding = reset($matches);
        $this->assertSame(ApiTestGrantUnreachableObject::class, $finding['declaringClass']);
    }

    /**
     * Declares both `update` and `action` — both resolve to `canEdit()` —
     * deliberately, so a single hard-overridden method reaching two
     * declared verbs produces one finding listing both, not two findings
     * or a finding that silently drops one.
     */
    public function testCollectsEveryVerbReachingTheSameMethodIntoOneFinding(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        $matches = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $finding['class'] === ApiTestGrantUnreachableObject::class
        ));

        $this->assertCount(1, $matches, 'update and action must collapse to a single finding, not two');
        $this->assertEqualsCanonicalizing(['update', 'action'], $matches[0]['verbs']);
    }

    public function testDoesNotFlagAClassWhoseCanEditOverrideCallsExtendedCanFirst(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        $matches = array_filter(
            $findings,
            fn (array $finding): bool => $finding['class'] === ApiTestGrantReachableObject::class
        );

        $this->assertEmpty(
            $matches,
            'a class whose canEdit() calls extendedCan() first must not be flagged, ' .
                'regardless of what the extension itself would answer'
        );
    }

    /**
     * ApiTestGrantExtraSourceObject carries ContentApiGrantExtension only
     * via an extension applied to it (ApiTestGrantExtraSourceExtension's
     * own `extensions` static, an "extra source" in Config terms) —
     * Extensible::getExtensionInstances() never actually instantiates it
     * for this class, so there's no real grant here to check reachability
     * for. A raw, non-excluding `extensions` Config read would wrongly
     * treat this as carrying the extension; DataObject::has_extension()
     * must not.
     */
    public function testDoesNotFlagAClassThatOnlyCarriesTheExtensionViaAnExtraSource(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        $matches = array_filter(
            $findings,
            fn (array $finding): bool => $finding['class'] === ApiTestGrantExtraSourceObject::class
        );

        $this->assertEmpty(
            $matches,
            'a class that only appears to carry ContentApiGrantExtension via another ' .
                'extension\'s own config contribution must not be flagged — it was never really applied'
        );
    }

    /**
     * ApiTestGrantAbstractObject can't be a real abstract DataObject
     * fixture — see its own docblock for why that crashes SilverStripe's
     * schema rebuild project-wide — so this exercises isConcrete()
     * directly via reflection rather than through check()'s full walk.
     */
    public function testIsConcreteExcludesAbstractClasses(): void
    {
        $checker = GrantExtensionReachabilityChecker::create();
        $isConcrete = new ReflectionMethod($checker, 'isConcrete');
        $isConcrete->setAccessible(true);

        $this->assertFalse($isConcrete->invoke($checker, ApiTestGrantAbstractObject::class));
        $this->assertTrue($isConcrete->invoke($checker, ApiTestGrantUnreachableObject::class));
    }
}
