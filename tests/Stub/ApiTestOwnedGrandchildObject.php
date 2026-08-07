<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Leaf of the #120 owned-tree fixture — no module class declares `$owns`
 * anywhere (confirmed by a full-repo grep; Elemental's own publish cascade
 * is hand-rolled, not `$owns`-driven), so this three-level hierarchy
 * (Parent -> Children -> Grandchildren, all `$owns`) exists purely to give
 * `OwnedTreeWalker`/`ParityHandler` a real multi-depth tree to walk.
 */
class ApiTestOwnedGrandchildObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedGrandchildObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Parent' => ApiTestOwnedChildObject::class,
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
