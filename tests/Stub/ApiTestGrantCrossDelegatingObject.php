<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Read-only, with a `canView()` that delegates to `parent::canEdit()` (the
 * shape of `ElementalArea::canView()`). That runs extendedCan('canEdit'),
 * which grants update/action, not read, so the read grant is never
 * consulted and the checker must flag it (#245).
 */
class ApiTestGrantCrossDelegatingObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantCrossDelegatingObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $content_api_access = 'read';

    private static array $extensions = [
        ContentApiGrantExtension::class,
    ];

    public function canView($member = null): ?bool
    {
        return parent::canEdit($member);
    }
}
