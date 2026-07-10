<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * SiteTree subclass for page-conversion tests.
 */
class ApiTestPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestPage';
}
