<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * #131 `FingerprintService` "related" section fixture — a direct-FK-to-page
 * relation, the same shape as the real prototype's own
 * `Dynamic\FlexSlider\Model\SlideImage.PageID` (a hero-image relation owned
 * directly by a page, not through an intermediate like an Elemental area).
 */
class ApiTestFingerprintRelatedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestFingerprintRelatedObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Page' => SiteTree::class,
    ];

    private static array $extensions = [
        Versioned::class,
        ExternalIdentifierExtension::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return true;
    }
}
