<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Elemental-enabled page type for composition tests. `SecondaryArea` is a
 * second `ElementalArea`-typed has_one alongside the default one
 * `ElementalPageExtension` provides — exists so a composition
 * `areaRelation` request has somewhere real to be routed WRONG if it's
 * silently ignored (#192's exact shape: a HomePage-style class with both a
 * generic default area and a real, differently-named one).
 */
class ApiTestBlockPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestBlockPage';

    private static array $has_one = [
        'SecondaryArea' => ElementalArea::class,
    ];

    private static array $owns = [
        'SecondaryArea',
    ];

    private static array $extensions = [
        ElementalPageExtension::class,
        ExternalIdentifierExtension::class,
    ];
}
