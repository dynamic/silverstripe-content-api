<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Contributes `ContentApiGrantExtension` to whatever class applies THIS
 * extension — used to prove `GrantExtensionReachabilityChecker` doesn't
 * false-positive on an `extensions` entry contributed by a different
 * extension, the same class of mistake `ClassRegistry::ownAccessVerbs()`
 * already guards against for `content_api_access` (see
 * {@see ApiTestExtraSourceAccessExtension}). `Extensible::
 * getExtensionInstances()` would never actually instantiate
 * `ContentApiGrantExtension` for a class carrying only this extension —
 * it's not a real grant.
 *
 * Declares the same `$registry` property `ContentApiGrantExtension`
 * itself does: Config's extra-source merge pulls that class's
 * `dependencies` static onto this class too (not just its `extensions`
 * entry), so Injector genuinely tries to set `$registry` here — without
 * a real typed property to receive it, that's a PHP dynamic-property
 * deprecation warning on every test run, not just this fixture's own.
 */
class ApiTestGrantExtraSourceExtension extends Extension implements TestOnly
{
    private static array $extensions = [
        ContentApiGrantExtension::class,
    ];

    public ?ClassRegistry $registry = null;
}
