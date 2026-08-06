<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Carries `ContentApiGrantExtension` and declares its own `create`/
 * `update`/`action` verbs (the latter two both resolve to `canEdit()` —
 * declaring both deliberately, so this stub also proves the checker
 * groups every verb reaching one resolved method into a single finding
 * rather than reporting `update` and `action` separately or dropping
 * one), same class shape as {@see ApiTestGrantReachableObject} — but
 * this `canEdit()` hard-overrides without ever calling `extendedCan()`,
 * the real-world FoxyStripe `ProductPage` shape #103 was filed against.
 * The grant extension's hook is never reached here, no matter what it
 * would answer. Used by `GrantExtensionReachabilityCheckerTest` to prove
 * the checker actually flags this class.
 */
class ApiTestGrantUnreachableObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantUnreachableObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $content_api_access = 'read,create,update,action';

    private static array $extensions = [
        ContentApiGrantExtension::class,
    ];

    public function canEdit($member = null): ?bool
    {
        // Deliberately never calls extendedCan() -- the bug #103 exists to
        // catch. A real-world equivalent might check a role field, a
        // workflow state, or (as in the FoxyStripe case) hard-code a
        // permission code -- the specific logic doesn't matter, only that
        // it bypasses the extension mechanism entirely. Safe to mention
        // extendedCan() by name in this comment: the checker strips
        // comments and string literals before matching, so only a real
        // call in actual code counts.
        return false;
    }
}
