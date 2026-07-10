<?php

namespace Dynamic\ContentApi\Security;

use SilverStripe\Security\PermissionProvider;

/**
 * Permission codes gating the content API. Assign these to the group backing
 * agent/service accounts.
 */
class ContentApiPermissions implements PermissionProvider
{
    public const ACCESS = 'CONTENT_API_ACCESS';

    public const POPULATE = 'CONTENT_API_POPULATE';

    public const SCHEMA = 'CONTENT_API_SCHEMA';

    public function providePermissions(): array
    {
        $category = 'Content API';

        return [
            self::ACCESS => [
                'name' => 'Access the content API',
                'category' => $category,
                'help' => 'Required for every content API endpoint. '
                    . 'Record-level canView/canEdit checks still apply.',
            ],
            self::POPULATE => [
                'name' => 'Use content population endpoints',
                'category' => $category,
                'help' => 'Batch operations, page compositions, asset uploads and page actions.',
            ],
            self::SCHEMA => [
                'name' => 'Read content API schema introspection',
                'category' => $category,
                'help' => 'Schema endpoints describing exposed classes and fields. '
                    . 'Members with content API access are granted this implicitly.',
            ],
        ];
    }
}
