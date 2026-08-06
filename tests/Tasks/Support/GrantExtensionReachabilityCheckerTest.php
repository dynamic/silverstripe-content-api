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

    /**
     * `T_CONSTANT_ENCAPSED_STRING` alone only covers non-interpolated
     * literals — an interpolated `"..."` body, a heredoc, or a nowdoc
     * tokenizes as `T_ENCAPSED_AND_WHITESPACE` and would otherwise leak
     * a prose mention of `extendedCan(` straight through the stripper,
     * exactly the false-"reachable" failure mode comment-stripping was
     * added to prevent — just relocated to a different token type.
     * Exercises stripCommentsAndStrings() directly with fabricated
     * source rather than through a real stub class, since a class body
     * containing an unreachable `extendedCan(` mention only inside a
     * heredoc/interpolated string, with no comment involved, isn't
     * meaningfully different to construct than to hand-write inline.
     */
    public function testStripsInterpolatedStringsAndHeredocsNotJustPlainLiterals(): void
    {
        $checker = GrantExtensionReachabilityChecker::create();
        $strip = new ReflectionMethod($checker, 'stripCommentsAndStrings');
        $strip->setAccessible(true);

        $interpolated = <<<'PHP'
            public function canEdit($member = null): ?bool
            {
                throw new \Exception("{$this->ClassName} bypasses extendedCan( entirely");
            }
            PHP;

        $heredoc = <<<'PHP'
            public function canEdit($member = null): ?bool
            {
                $msg = <<<MSG
                bypasses extendedCan( entirely
                MSG;
                throw new \Exception($msg);
            }
            PHP;

        $this->assertStringNotContainsString(
            'extendedCan(',
            $strip->invoke($checker, $interpolated),
            'a mention inside an interpolated string must not survive stripping'
        );
        $this->assertStringNotContainsString(
            'extendedCan(',
            $strip->invoke($checker, $heredoc),
            'a mention inside a heredoc body must not survive stripping'
        );
    }
}
