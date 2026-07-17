<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * has_many child of ApiTestElement (versioned, like real element children).
 * Titling a record "Invalid" fails validate(), matching ApiTestObject's
 * convention, so composition child-write can exercise VALIDATION_FAILED
 * mapping.
 */
class ApiTestElementItem extends DataObject implements TestOnly
{
    use InvalidFieldValidationTrait;

    private static string $table_name = 'ContentApi_ApiTestElementItem';

    private static array $db = [
        'Title' => 'Varchar',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'Element' => ApiTestElement::class,
    ];

    private static array $extensions = [
        Versioned::class,
        ExternalIdentifierExtension::class,
    ];

    public function validate(): ValidationResult
    {
        return $this->rejectInvalidFieldValue(parent::validate(), 'Title');
    }

    public function canView($member = null): bool
    {
        return true;
    }
}
