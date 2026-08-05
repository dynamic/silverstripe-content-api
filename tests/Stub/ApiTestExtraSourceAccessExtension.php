<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Declares its own `content_api_access` — used to prove
 * ClassRegistry::ownAccessVerbs() reads `Config::EXCLUDE_EXTRA_SOURCES` too,
 * not just `Config::UNINHERITED`: a value contributed by an extension
 * applied to a class must NOT count as that class's own declaration, or a
 * class could be opted into ContentApiGrantExtension's grant by any
 * unrelated extension it happens to carry.
 */
class ApiTestExtraSourceAccessExtension extends Extension implements TestOnly
{
    private static string $content_api_access = 'GET,POST,PUT,DELETE,action';
}
