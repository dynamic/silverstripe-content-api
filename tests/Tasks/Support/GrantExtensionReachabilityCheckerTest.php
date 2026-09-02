<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\GrantExtensionReachabilityChecker;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantAbstractObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantExtraSourceObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantMissingExtensionObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantMissingExtensionReadOnlyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantMissingExtensionSubObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantMissingExtensionWritableFieldsOnlyObject;
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
        ApiTestGrantMissingExtensionObject::class,
        ApiTestGrantMissingExtensionReadOnlyObject::class,
        ApiTestGrantMissingExtensionWritableFieldsOnlyObject::class,
        ApiTestGrantMissingExtensionSubObject::class,
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

    /**
     * #197: a class declaring write access but carrying no
     * ContentApiGrantExtension at all is invisible to check() — it never
     * satisfies check()'s own carriesGrantExtension() filter — so this is
     * the sibling method's whole reason to exist.
     */
    public function testCheckMissingGrantExtensionFlagsAClassWithNoExtensionAtAll(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->checkMissingGrantExtension();

        $matches = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $finding['class'] === ApiTestGrantMissingExtensionObject::class
        ));

        $this->assertCount(
            1,
            $matches,
            'a class with api_access/api_writable_fields but no ContentApiGrantExtension ' .
                'anywhere in its hierarchy must be flagged'
        );
        $this->assertEqualsCanonicalizing(['read', 'create', 'update'], $matches[0]['verbs']);
        $this->assertSame(['Title'], $matches[0]['writableFields']);
    }

    /**
     * check() itself is blind to this class (no extension to check
     * reachability of) — confirms the two diagnostics really are disjoint,
     * not that checkMissingGrantExtension() duplicates check()'s job.
     */
    public function testCheckItselfDoesNotFlagAClassWithNoExtensionAtAll(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        $matches = array_filter(
            $findings,
            fn (array $finding): bool => $finding['class'] === ApiTestGrantMissingExtensionObject::class
        );

        $this->assertEmpty(
            $matches,
            'check() has no extension to test reachability of on this class — it must not appear there'
        );
    }

    /**
     * Negative control: a class that legitimately carries the extension
     * must never appear in checkMissingGrantExtension()'s results, however
     * its own can*() methods behave — that question belongs to check().
     */
    public function testCheckMissingGrantExtensionDoesNotFlagAClassThatCarriesTheExtension(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->checkMissingGrantExtension();

        $flaggedClasses = array_column($findings, 'class');

        $this->assertNotContains(ApiTestGrantReachableObject::class, $flaggedClasses);
        $this->assertNotContains(ApiTestGrantUnreachableObject::class, $flaggedClasses);
    }

    /**
     * ApiTestGrantExtraSourceObject only appears to carry
     * ContentApiGrantExtension (contributed by another extension's own
     * config, an "extra source" carriesGrantExtension() correctly
     * excludes — see testDoesNotFlagAClassThatOnlyCarriesTheExtensionViaAnExtraSource()
     * above). That means there is no *real* grant here, and this class
     * declares its own write verbs (`create`, `action`) — it should be a
     * positive finding for checkMissingGrantExtension() too, not just
     * absent from check()'s different question.
     */
    public function testCheckMissingGrantExtensionFlagsAClassThatOnlyCarriesTheExtensionViaAnExtraSource(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->checkMissingGrantExtension();

        $flaggedClasses = array_column($findings, 'class');

        $this->assertContains(
            ApiTestGrantExtraSourceObject::class,
            $flaggedClasses,
            'a class whose only ContentApiGrantExtension "grant" is extra-source pollution ' .
                'has no real grant hook — it must be flagged the same as a class with no extension at all'
        );
    }

    /**
     * #197 negative control: a class declaring `read` only (no
     * create/update/delete/action) has nothing for a missing grant
     * extension to break, and flagging it would point at the wrong fix
     * (a missing api_access write grant, not a missing extension) —
     * proves checkMissingGrantExtension() gates on write verbs
     * specifically, not on "any exposure at all".
     */
    public function testCheckMissingGrantExtensionDoesNotFlagAReadOnlyClass(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->checkMissingGrantExtension();

        $flaggedClasses = array_column($findings, 'class');

        $this->assertNotContains(ApiTestGrantMissingExtensionReadOnlyObject::class, $flaggedClasses);
    }

    /**
     * #197 negative control: api_writable_fields with no api_access grant
     * at all isn't exposed for writes through this API in the first place
     * (WriteApplicator's allowlist only matters once a class is already
     * exposed) — nothing here for a missing extension to break either.
     */
    public function testCheckMissingGrantExtensionDoesNotFlagAClassWithOnlyWritableFieldsAndNoAccessGrant(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->checkMissingGrantExtension();

        $flaggedClasses = array_column($findings, 'class');

        $this->assertNotContains(ApiTestGrantMissingExtensionWritableFieldsOnlyObject::class, $flaggedClasses);
    }

    /**
     * #197: a subclass declaring nothing of its own must not produce a
     * duplicate/spurious finding alongside its parent — only the class
     * that actually declares the exposure (where adding the extension
     * fixes the whole family) should be reported.
     */
    public function testCheckMissingGrantExtensionFlagsOnlyTheDeclaringParentNotAnInheritingSubclass(): void
    {
        $findings = GrantExtensionReachabilityChecker::create()->checkMissingGrantExtension();

        $flaggedClasses = array_column($findings, 'class');

        $this->assertContains(ApiTestGrantMissingExtensionObject::class, $flaggedClasses);
        $this->assertNotContains(ApiTestGrantMissingExtensionSubObject::class, $flaggedClasses);
    }
}
