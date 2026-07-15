<?php

namespace Dynamic\ContentApi\Tests\Errors;

use Dynamic\ContentApi\Errors\ApiError;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\SapphireTest;

class ApiErrorTest extends SapphireTest
{
    public function testFromValidationMapsStructuredMessagesNotRawExceptionText(): void
    {
        $result = ValidationResult::create()->addFieldError('Title', 'Title is required.');
        $exception = new ValidationException($result);

        $error = ApiError::fromValidation($exception);

        $this->assertSame('VALIDATION_FAILED', $error->getErrorCode()->value);
        $this->assertSame(422, $error->getStatus());

        // The top-level message is a fixed, generic summary — never the raw
        // ValidationException::getMessage() text, which (in CLI/dev context)
        // can append `- fieldName: X, recordID: Y, dataClass: Z` (#21).
        $this->assertSame('1 field(s) failed validation.', $error->getMessage());
        $this->assertStringNotContainsString('recordID', $error->getMessage());
        $this->assertStringNotContainsString('dataClass', $error->getMessage());

        $details = $error->getDetails();
        $this->assertCount(1, $details);
        $this->assertSame('Title', $details[0]['field']);
        $this->assertSame('VALIDATION', $details[0]['code']);
        $this->assertSame('Title is required.', $details[0]['message']);
    }

    public function testFromValidationPrefixesContextWithoutTouchingRawExceptionText(): void
    {
        $result = ValidationResult::create()->addFieldError('Title', 'Title is required.');
        $exception = new ValidationException($result);

        $error = ApiError::fromValidation($exception, 'Child "widget-1"');

        $this->assertSame('Child "widget-1": 1 field(s) failed validation.', $error->getMessage());
    }

    public function testFromValidationCountsMultipleFieldErrors(): void
    {
        $result = ValidationResult::create()
            ->addFieldError('Title', 'Title is required.')
            ->addFieldError('URLSegment', 'URLSegment is required.');
        $exception = new ValidationException($result);

        $error = ApiError::fromValidation($exception);

        $this->assertSame('2 field(s) failed validation.', $error->getMessage());
        $this->assertCount(2, $error->getDetails());
    }
}
