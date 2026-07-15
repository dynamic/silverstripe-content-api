<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Carries a polymorphic has_one (declared against the abstract DataObject
 * base, same shape as core's UserForms\EmailRecipient.Form) for issue #17
 * regression coverage — CREATE via batch/compositions with an "Owner" that
 * has no single target class to infer from the schema.
 */
class ApiTestPolyObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestPolyObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Owner' => DataObject::class,
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    private static bool $api_access = true;

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
}
