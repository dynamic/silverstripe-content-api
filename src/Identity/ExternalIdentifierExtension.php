<?php

namespace Dynamic\ContentApi\Identity;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;

/**
 * Adds the external identifier column used for idempotent API upserts.
 *
 * The column spec is deliberately byte-identical to the fixtures recipe's
 * FixtureRecordExtension (`FixtureIdentifier Varchar(100)`, indexed) so that
 * on Essentials sites where both extensions are applied the config merge
 * yields a single column, and records populated by the legacy YAML workflow
 * are immediately addressable through the API.
 *
 * Apply per-project to the classes the API should manage, e.g.:
 *
 * ```yml
 * SilverStripe\CMS\Model\SiteTree:
 *   extensions:
 *     - Dynamic\ContentApi\Identity\ExternalIdentifierExtension
 * ```
 *
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class ExternalIdentifierExtension extends Extension
{
    private static array $db = [
        'FixtureIdentifier' => 'Varchar(100)',
    ];

    private static array $indexes = [
        'FixtureIdentifier' => true,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('FixtureIdentifier');

        if ($this->getOwner()->FixtureIdentifier) {
            $fields->addFieldToTab(
                'Root.Main',
                ReadonlyField::create('FixtureIdentifier', 'External identifier')
            );
        }
    }
}
