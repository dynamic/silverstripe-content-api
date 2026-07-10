<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\TestOnly;

/**
 * Element with a has_many child relation and an image has_one — the shape of
 * real Essentials elements (accordion panels, stat counters, gallery images).
 */
class ApiTestElement extends BaseElement implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestElement';

    private static string $singular_name = 'Test Element';

    private static array $db = [
        'Intro' => 'Varchar',
    ];

    private static array $has_one = [
        'Photo' => Image::class,
        'Cta' => \SilverStripe\LinkField\Models\Link::class,
    ];

    private static array $has_many = [
        'Items' => ApiTestElementItem::class,
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    public function getType(): string
    {
        return 'Test Element';
    }
}
