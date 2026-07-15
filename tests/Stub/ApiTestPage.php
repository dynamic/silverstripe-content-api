<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;

/**
 * SiteTree subclass for page-conversion tests. Titling a record "Invalid"
 * fails validate(), matching ApiTestObject's convention, so composition
 * page-creation and conversion can exercise VALIDATION_FAILED mapping.
 */
class ApiTestPage extends SiteTree implements TestOnly
{
    use InvalidFieldValidationTrait;

    private static string $table_name = 'ContentApi_ApiTestPage';

    public function validate(): ValidationResult
    {
        return $this->rejectInvalidFieldValue(parent::validate(), 'Title');
    }
}
