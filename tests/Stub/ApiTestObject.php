<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Core\Validation\ValidationResult;
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
        // An Enum field — exists solely so write-path enum-value validation
        // has a real enum column to test against (WriteApplicator never
        // validated a write against the field's own declared value list;
        // an out-of-list value was accepted and written as-is, then
        // silently coerced to '' by MySQL's own ENUM column — confirmed
        // live, 46 elements, essentials project).
        'Status' => "Enum('draft,published', 'draft')",
        // DBMultiEnum extends DBEnum and stores a comma-joined list of
        // independently-valid values, not one — exists so the enum-value
        // check's DBMultiEnum branch has a real 'set'-backed column to
        // test against (validating the whole joined string against
        // enumValues() directly, instead of each comma-separated piece,
        // would reject every legitimate multi-value write).
        'Colors' => "MultiEnum('red,green,blue', 'red')",
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
