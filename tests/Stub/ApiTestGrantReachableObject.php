<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Carries `ContentApiGrantExtension` and declares its own `action`/`create`
 * verbs, with a `canEdit()` override that calls `extendedCan()` first —
 * the safe pattern `SiteTree`'s own `can*()` methods follow. Paired with
 * {@see ApiTestGrantUnreachableObject} in
 * `GrantExtensionReachabilityCheckerTest` to prove the checker only flags
 * the unreachable case, not every class the extension is applied to.
 */
class ApiTestGrantReachableObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantReachableObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $content_api_access = 'read,create,action';

    private static array $extensions = [
        ContentApiGrantExtension::class,
    ];

    public function canEdit($member = null): ?bool
    {
        $extended = $this->extendedCan('canEdit', $member);

        if ($extended !== null) {
            return $extended;
        }

        return false;
    }
}
