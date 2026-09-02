<?php

namespace Dynamic\ContentApi\Tests\Errors;

use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Dev\SapphireTest;

/**
 * `ErrorCode::httpStatus()` is an exhaustive `match` with no default arm —
 * a case added without a status arm is an `UnhandledMatchError` at
 * runtime, not a compile-time or static-analysis failure. This test is the
 * only thing that would catch that before a real request hits it.
 */
class ErrorCodeTest extends SapphireTest
{
    public function testEveryCaseMapsToAValidHttpStatus(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $status = $code->httpStatus();

            $this->assertGreaterThanOrEqual(400, $status, $code->value . ' must map to an error status');
            $this->assertLessThan(600, $status, $code->value . ' must map to a valid HTTP status');
        }
    }
}
