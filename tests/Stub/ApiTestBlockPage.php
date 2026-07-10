<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use DNADesign\Elemental\Extensions\ElementalPageExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Elemental-enabled page type for composition tests.
 */
class ApiTestBlockPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestBlockPage';

    private static array $extensions = [
        ElementalPageExtension::class,
        ExternalIdentifierExtension::class,
    ];
}
