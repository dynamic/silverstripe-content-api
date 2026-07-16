<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

/**
 * Same polymorphic has_one shape as ApiTestPolyObject, but declared via the
 * array/`multirelational` form SilverStripe requires when the same
 * polymorphic has_one is shared by more than one reciprocal has_many
 * (`DataObjectSchema::checkHasOneArraySpec()` — `multirelational: true`
 * requires `'class' => DataObject::class`). Regression coverage for #34:
 * every module call site reads the spec through `hasOne()`/
 * `hasOneComponent()`, which the framework normalizes to a bare class
 * string before any module code sees it — this stub exists to prove that
 * (or find the gap where it isn't true).
 */
class ApiTestMultiRelationalPolyObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestMultiRelationalPolyObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Owner' => [
            'class' => DataObject::class,
            DataObjectSchema::HAS_ONE_MULTI_RELATIONAL => true,
        ],
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return true;
    }

    public function canEdit($member = null): bool
    {
        return true;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }
}
