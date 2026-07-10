<?php

namespace Dynamic\ContentApi\Write\Transformers;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Write\ValueTransformer;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;

/**
 * Turns a structured link payload on a has_one-to-Link field into a written
 * silverstripe/linkfield record, returning its ID for the FK:
 *
 * ```json
 * "CtaLink": { "type": "ExternalLink", "url": "/contact",
 *              "title": "Contact us", "openInNew": false }
 * ```
 *
 * Types: ExternalLink (url), SiteTreeLink (pageId, anchor), EmailLink
 * (email), PhoneLink (phone), FileLink (fileId). Site-relative ExternalLink
 * urls ('/contact') are the proven environment-independent convention.
 *
 * Sparse: when the record already points at a link of the same type, that
 * link is updated in place (idempotent re-runs). A type change writes a new
 * link record. Owner fields are never set here — linkfield derives ownership
 * from the pointing has_one (setting Owner* before the owner exists crashes).
 *
 * Registered only when silverstripe/linkfield is installed (linkfield.yml).
 */
class LinkTransformer implements ValueTransformer
{
    use Configurable;
    use Injectable;

    private const LINK_BASE_CLASS = 'SilverStripe\\LinkField\\Models\\Link';

    /**
     * Payload `type` values to linkfield classes.
     */
    private static array $type_map = [
        'ExternalLink' => 'SilverStripe\\LinkField\\Models\\ExternalLink',
        'SiteTreeLink' => 'SilverStripe\\LinkField\\Models\\SiteTreeLink',
        'EmailLink' => 'SilverStripe\\LinkField\\Models\\EmailLink',
        'PhoneLink' => 'SilverStripe\\LinkField\\Models\\PhoneLink',
        'FileLink' => 'SilverStripe\\LinkField\\Models\\FileLink',
    ];

    /**
     * Payload keys to link db/FK fields.
     */
    private const FIELD_MAP = [
        'title' => 'LinkText',
        'openInNew' => 'OpenInNew',
        'url' => 'ExternalUrl',
        'email' => 'Email',
        'phone' => 'Phone',
        'pageId' => 'PageID',
        'anchor' => 'Anchor',
        'fileId' => 'FileID',
    ];

    public function supports(DataObject $record, string $fieldName, mixed $value): bool
    {
        if (!is_array($value) || !isset($value['type']) || !class_exists(LinkTransformer::LINK_BASE_CLASS)) {
            return false;
        }

        $relationClass = DataObject::getSchema()->hasOneComponent(get_class($record), $fieldName);

        return $relationClass && is_a($relationClass, LinkTransformer::LINK_BASE_CLASS, true);
    }

    public function transform(DataObject $record, string $fieldName, mixed $value): mixed
    {
        $typeMap = (array) static::config()->get('type_map');
        $type = (string) $value['type'];
        $linkClass = $typeMap[$type] ?? null;

        if (!$linkClass || !class_exists($linkClass)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Unknown link type "%s" for "%s" — use one of: %s.',
                    $type,
                    $fieldName,
                    implode(', ', array_keys($typeMap))
                )
            );
        }

        $link = null;
        $existingId = (int) $record->getField($fieldName . 'ID');

        if ($existingId > 0) {
            $existing = DataObject::get_by_id(LinkTransformer::LINK_BASE_CLASS, $existingId);

            if ($existing && $existing->ClassName === $linkClass) {
                $link = $existing;
            }
        }

        if (!$link) {
            $link = Injector::inst()->create($linkClass);
        }

        foreach (LinkTransformer::FIELD_MAP as $payloadKey => $linkField) {
            if (array_key_exists($payloadKey, $value)) {
                $link->setCastedField($linkField, $value[$payloadKey]);
            }
        }

        try {
            $link->write();
        } catch (ValidationException $exception) {
            throw new ApiError(
                ErrorCode::VALIDATION_FAILED,
                sprintf('Link for "%s" failed validation: %s', $fieldName, $exception->getMessage())
            );
        }

        if ($link->hasMethod('publishSingle')) {
            $link->publishSingle();
        }

        return (int) $link->ID;
    }
}
