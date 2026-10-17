<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Allows edit but denies publish and unpublish — the case a CMS honours
 * and the content API must too (#235).
 */
class ApiTestPublishDenyingExtension extends Extension implements TestOnly
{
    public function canEdit($member = null): ?bool
    {
        return true;
    }

    public function canPublish($member = null): ?bool
    {
        return false;
    }

    public function canUnpublish($member = null): ?bool
    {
        return false;
    }
}
