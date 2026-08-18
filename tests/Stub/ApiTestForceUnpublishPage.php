<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Permission;

/**
 * ApiTestPage subclass with the same canEdit()-always/canDelete()-ADMIN-only
 * split as ApiTestVersionedObject — needed separately here because #80's
 * force-unpublish delete-verb gate only ever fires on a `SiteTree` class
 * (see PublishOrchestrator::forceCouldStrandDescendants()), and
 * ApiTestVersionedObject isn't one. No new `$db` fields, so (like
 * ApiTestGrantSubPage) this needs no `ContentApiTestCase::$extra_dataobjects`
 * entry — SiteTree subclasses share the base table.
 */
class ApiTestForceUnpublishPage extends ApiTestPage implements TestOnly
{
    public function canEdit($member = null): bool
    {
        return true;
    }

    public function canDelete($member = null): bool
    {
        return (bool) Permission::checkMember($member, 'ADMIN');
    }
}
