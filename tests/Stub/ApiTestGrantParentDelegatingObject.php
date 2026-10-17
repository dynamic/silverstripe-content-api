<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Carries `ContentApiGrantExtension` with its own verbs, and a `canEdit()`
 * that only delegates to `parent::canEdit()`, the shape of
 * `ElementalArea::canEdit()`. `DataObject::canEdit()` reaches the grant, so
 * the reachability checker must not flag it (#245).
 */
class ApiTestGrantParentDelegatingObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantParentDelegatingObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $content_api_access = 'read,update';

    private static array $extensions = [
        ContentApiGrantExtension::class,
    ];

    public function canEdit($member = null): ?bool
    {
        if (parent::canEdit($member)) {
            return true;
        }

        return false;
    }
}
