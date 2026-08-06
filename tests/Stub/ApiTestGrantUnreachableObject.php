<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Carries `ContentApiGrantExtension` and declares its own `action`/`create`
 * verbs, same as {@see ApiTestGrantReachableObject} — but this
 * `canEdit()` hard-overrides without ever calling `extendedCan()`, the
 * real-world FoxyStripe `ProductPage` shape #103 was filed against. The
 * grant extension's hook is never reached here, no matter what it would
 * answer. Used by `GrantExtensionReachabilityCheckerTest` to prove the
 * checker actually flags this class.
 */
class ApiTestGrantUnreachableObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantUnreachableObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $content_api_access = 'read,create,action';

    private static array $extensions = [
        ContentApiGrantExtension::class,
    ];

    public function canEdit($member = null): ?bool
    {
        // Deliberately skips the standard extension-consultation hook the
        // bug #103 exists to catch. A real-world equivalent might check a
        // role field, a workflow state, or (as in the FoxyStripe case)
        // hard-code a permission code — the specific logic doesn't
        // matter, only that it bypasses the mechanism entirely. (This
        // comment must not spell out the hook method's name literally —
        // GrantExtensionReachabilityCheckerTest asserts on its absence
        // from this method's source, and the checker itself can't tell a
        // real call from a mention in prose.)
        return false;
    }
}
