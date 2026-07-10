<?php

namespace Dynamic\ContentApi\Auth;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;

/**
 * Adds API token storage to Member. Only a sha256 hash of the token is stored;
 * the plaintext token is returned once at mint time and never persisted.
 *
 * Column names are deliberately distinct from colymba/silverstripe-restfulapi's
 * ApiToken/ApiTokenExpire so both modules can coexist on one site.
 *
 * @extends Extension<\SilverStripe\Security\Member>
 */
class ApiTokenExtension extends Extension
{
    private static array $db = [
        'ContentApiTokenHash' => 'Varchar(64)',
        'ContentApiTokenExpire' => 'Int',
    ];

    private static array $indexes = [
        'ContentApiTokenHash' => true,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['ContentApiTokenHash', 'ContentApiTokenExpire']);
    }
}
