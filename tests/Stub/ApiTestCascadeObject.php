<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Write\WriteGuardExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Guarded stub whose write cascades into a sibling of the same class within
 * the same request — the scenario for the WriteGuardExtension cascade bug
 * (a sibling write during an API request must not have the API target's
 * field policy applied to it).
 *
 * The "lead" record (IsLead=1) bumps the "follower" record's Marker in
 * onAfterWrite. The follower carries WriteGuardExtension too but was never
 * deserialized from the API payload, so the guard must leave its Marker alone.
 */
class ApiTestCascadeObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestCascadeObject';

    private static array $db = [
        'Title' => 'Varchar',
        'Marker' => 'Int',
        'IsLead' => 'Boolean',
    ];

    private static array $extensions = [
        WriteGuardExtension::class,
    ];

    private static bool $cascading = false;

    public function canView($member = null): bool
    {
        return true;
    }

    public function canEdit($member = null): bool
    {
        return true;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        if (!$this->IsLead || ApiTestCascadeObject::$cascading) {
            return;
        }

        $follower = ApiTestCascadeObject::get()->filter('IsLead', 0)->first();

        if (!$follower) {
            return;
        }

        ApiTestCascadeObject::$cascading = true;

        try {
            $follower->Marker = $follower->Marker + 5;
            $follower->write();
        } finally {
            ApiTestCascadeObject::$cascading = false;
        }
    }
}
