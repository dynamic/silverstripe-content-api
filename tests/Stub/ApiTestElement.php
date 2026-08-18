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
        'PlainItems' => ApiTestPlainChildObject::class,
    ];

    /**
     * The config `DataObject::duplicate()` consults when passed no relation
     * list, and therefore the config that decides which children a template
     * application creates. `Items` is versioned and `PlainItems` isn't, so
     * #174's cascade gets both cases from one element.
     *
     * Declared here WITHOUT a matching `$owns` on purpose — that combination
     * is what #174 is about, and it's what makes this a discriminating
     * fixture. Stock Dynamic elements don't currently hit it: the ones with
     * versioned children (`ElementPhotoGallery => Images`,
     * `ElementCard => ElementLink`) list those in `$owns` too, so the
     * publish walk already reached them, and the one genuine cascade-only
     * case (`ElementStatCounters => Stats`) has an unversioned child with
     * nothing to publish.
     */
    private static array $cascade_duplicates = [
        'Items',
        'PlainItems',
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    public function getType(): string
    {
        return 'Test Element';
    }
}
