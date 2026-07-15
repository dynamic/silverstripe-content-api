<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;
use SilverStripe\LinkField\Models\Link;

/**
 * Link subclass registered only via a test's own
 * `Config::modify()->set(LinkTransformer::class, 'type_map', ...)` override —
 * lets LinkTransformer's write path exercise VALIDATION_FAILED mapping
 * without depending on a real linkfield model having its own validate()
 * rules. LinkText "Invalid" fails validate(), matching the module's other
 * test stubs.
 */
class ApiTestLink extends Link implements TestOnly
{
    use InvalidFieldValidationTrait;

    private static string $table_name = 'ContentApi_ApiTestLink';

    public function validate(): ValidationResult
    {
        return $this->rejectInvalidFieldValue(parent::validate(), 'LinkText');
    }
}
