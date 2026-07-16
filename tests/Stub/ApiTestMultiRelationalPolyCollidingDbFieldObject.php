<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

/**
 * Declares a plain `$db` field named exactly the same as the synthetic
 * companion column a multirelational has_one produces ('OwnerRelation'),
 * to pin `DataObjectSchema::databaseFields()`'s own merge order: plain
 * `$db` fields are merged first, has_one-derived composite columns
 * (including this one's `Owner` has_one's `Relation` sub-field) are merged
 * second and unconditionally win. See
 * WriteApplicatorTest::testFrameworkCompositeColumnWinsOverAPlainDbFieldOfTheSameName()
 * and WriteApplicator::polymorphicCompanionColumnRelation()'s docblock (#34).
 */
class ApiTestMultiRelationalPolyCollidingDbFieldObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestMultiRelationalPolyCollidingDbFieldObject';

    private static array $db = [
        'OwnerRelation' => 'Varchar',
    ];

    private static array $has_one = [
        'Owner' => [
            'class' => DataObject::class,
            DataObjectSchema::HAS_ONE_MULTI_RELATIONAL => true,
        ],
    ];
}
