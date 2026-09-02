<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * Declares nothing of its own — inherits `api_access`/`api_writable_fields`
 * from {@see ApiTestGrantMissingExtensionObject} through normal PHP class
 * inheritance. `checkMissingGrantExtension()` reads exposure via the
 * uninherited `Config::UNINHERITED` flag deliberately (see that method's
 * own docblock): the parent, which actually declares the exposure, is the
 * one place adding the extension fixes the whole family, so only the
 * parent should be flagged — this subclass proves it doesn't also produce
 * a duplicate/spurious finding of its own.
 */
class ApiTestGrantMissingExtensionSubObject extends ApiTestGrantMissingExtensionObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantMissingExtSubObject';
}
