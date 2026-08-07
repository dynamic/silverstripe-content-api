<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Unversioned test model. Records titled "Secret" refuse canView so list
 * filtering and record-level 403s can be exercised; titling a record
 * "Invalid" fails validate() for VALIDATION_FAILED mapping tests.
 */
class ApiTestObject extends DataObject implements TestOnly
{
    use InvalidFieldValidationTrait;

    private static string $table_name = 'ContentApi_ApiTestObject';

    private static array $db = [
        'Title' => 'Varchar',
        'Rank' => 'Int',
        // A composite DBField — exists solely so #127's rollback
        // pre-image capture has a real composite column to be tested
        // against (see
        // RecordWriterTest::testPreImageOfACompositeFieldIsAnImmutableSnapshotNotALiveReference()).
        'Price' => 'Money',
    ];

    private static array $has_one = [
        'Buddy' => ApiTestObject::class,
    ];

    private static array $has_many = [
        'Children' => ApiTestChildObject::class,
    ];

    private static array $many_many = [
        'Tags' => ApiTestTag::class,
        'ThroughTags' => [
            'through' => ApiTestThroughJoin::class,
            'from' => 'Owner',
            'to' => 'Tag',
        ],
    ];

    private static array $many_many_extraFields = [
        'Tags' => [
            'SortOrder' => 'Int',
        ],
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    private static bool $api_access = true;

    public function validate(): ValidationResult
    {
        return $this->rejectInvalidFieldValue(parent::validate(), 'Title');
    }

    public function canView($member = null): bool
    {
        return $this->Title !== 'Secret';
    }

    public function canEdit($member = null): bool
    {
        return true;
    }

    public function canDelete($member = null): bool
    {
        return true;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }
}
