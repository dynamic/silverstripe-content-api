<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A root with an owned asset relation — built for #119's unpublish-owns
 * shared-asset guard. Two independent instances of this class can point
 * their `Asset` has_one at the SAME live `Image`, the same shape the issue
 * itself confirmed live (one file ID simultaneously serving a hero slide's
 * image, a CTA card's image, and a page's own product image): an `owns`
 * unpublish cascade on one root must never pull a `File`/`Image` out from
 * under the other, so `PublishOrchestrator::UNPUBLISH_EXCLUDED_CLASSES`
 * must prune it before it's ever authorization-checked or unpublished —
 * confirmed by this stub carrying no `ContentApiGrantExtension`/grant on
 * `Image` at all, so a test using it only passes if the exclusion runs
 * before authorization, not merely alongside it.
 */
class ApiTestOwnedAssetOwnerObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedAssetOwnerObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Asset' => Image::class,
    ];

    private static array $owns = [
        'Asset',
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
