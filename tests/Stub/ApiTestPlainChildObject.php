<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A has_many child of ApiTestElement that is deliberately NOT Versioned —
 * the shape of Dynamic\Elements\StatCounters\Model\StatCounter, a real
 * Essentials child model with no publishSingle() method. Composition's
 * publish:recursive must not assume every child is Versioned (#37).
 */
class ApiTestPlainChildObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestPlainChildObject';

    private static array $db = [
        'Title' => 'Varchar',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'Element' => ApiTestElement::class,
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    public function canView($member = null): bool
    {
        return true;
    }
}
