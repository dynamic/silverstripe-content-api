<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\ORM\ValidationResult;

/**
 * Shared validate() sentinel used across test stubs: a record whose named
 * field is "Invalid" fails validation. Used to exercise VALIDATION_FAILED /
 * ApiError::fromValidation() mapping end-to-end without each stub
 * hand-rolling the same check.
 */
trait InvalidFieldValidationTrait
{
    protected function rejectInvalidFieldValue(ValidationResult $result, string $fieldName): ValidationResult
    {
        if ($this->getField($fieldName) === 'Invalid') {
            $result->addFieldError($fieldName, sprintf('%s may not be "Invalid".', $fieldName));
        }

        return $result;
    }
}
