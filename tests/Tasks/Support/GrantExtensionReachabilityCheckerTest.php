<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\GrantExtensionReachabilityChecker;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantReachableObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantUnreachableObject;
use SilverStripe\Dev\SapphireTest;

class GrantExtensionReachabilityCheckerTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ApiTestGrantReachableObject::class,
        ApiTestGrantUnreachableObject::class,
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
        $this->assertSame('action', $finding['verb']);
        $this->assertSame(ApiTestGrantUnreachableObject::class, $finding['declaringClass']);
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
}
