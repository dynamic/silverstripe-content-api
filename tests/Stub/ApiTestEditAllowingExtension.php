<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Allows edit, so a non-admin member passes the root of a cascade and the
 * refusal under test comes from a record the cascade reaches.
 */
class ApiTestEditAllowingExtension extends Extension implements TestOnly
{
    public function canEdit($member = null): ?bool
    {
        return true;
    }
}
