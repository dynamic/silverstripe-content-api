<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Denies canDelete() — proves composition prune's record-level check, not
 * just its class verb (#227).
 */
class ApiTestDeleteDenyingExtension extends Extension implements TestOnly
{
    public function canDelete($member = null): ?bool
    {
        return false;
    }
}
