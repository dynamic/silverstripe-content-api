<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Regression stub for #70 — mirrors dynamic/foxystripe's real-world
 * `ProductPage::onBeforeWrite()`, which calls `trim()` on a nullable field
 * with no null-guard: a genuine PHP 8.1+ deprecation notice, not a caught
 * exception. In a dev/test environment, SilverStripe's default error
 * handler `echo`s an HTML debug block directly to output for a diagnostic
 * like this — bypassing the controller's own `HTTPResponse` entirely unless
 * something buffers it (`ContentApiController::withEnvelope()`, as of #70).
 */
class ApiTestDeprecatingObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestDeprecatingObject';

    private static array $db = [
        'Title' => 'Varchar',
        'ReceiptTitle' => 'Varchar',
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $this->ReceiptTitle = trim($this->ReceiptTitle);
    }
}
