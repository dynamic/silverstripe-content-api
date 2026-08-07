<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * #131 `FingerprintService` regression fixture: a `related_classes`
 * subclass instantiated polymorphically via `DataObject::get($className)`
 * on its PARENT class, with its OWN explicit `content_api_access: false`
 * (set in `ContentApiTestCase::setUp()`). Proves `buildRelated()` checks
 * each row's own actual class, not just the class configured/exposed at
 * the `related_classes` ref level — the same per-row class-level gap
 * `isPageVisible()` closes for `pages`.
 */
class ApiTestFingerprintRelatedDeniedSubclassObject extends ApiTestFingerprintRelatedObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestFPRelatedDeniedSub';
}
