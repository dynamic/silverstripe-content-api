<?php

namespace Dynamic\ContentApi\Write\Transformers;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Write\ValueTransformer;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationException;
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

        // Any payload key other than "type" and FIELD_MAP's own keys used
        // to be silently ignored below — a caller sending "fileID"/"file"/
        // "target" instead of the real key "fileId" got a 200 and an empty
        // link record, with nothing in the response to say the key never
        // matched anything (confirmed live — #195).
        //
        // FIELD_MAP is a flat union across every link TYPE — checking a key
        // against it alone isn't enough, because a key valid for a
        // DIFFERENT type (e.g. "fileId" with "type": "ExternalLink") would
        // still pass that check and reach setCastedField() below.
        // DataObject::setCastedField() falls back to a bare, never-
        // persisted dynamic property when dbObject() finds no matching
        // column on the class actually being written — the exact same
        // silent-no-op shape #195 was filed for, just reachable through a
        // cross-type key instead of a wholly unrecognized one (confirmed:
        // FileID has no column on ExternalLink). So each key is validated
        // against the RESOLVED $linkClass's own schema, not just FIELD_MAP.
        $unknown = [];
        $wrongType = [];

        foreach (array_keys($value) as $payloadKey) {
            if ($payloadKey === 'type') {
                continue;
            }

            if (!array_key_exists($payloadKey, LinkTransformer::FIELD_MAP)) {
                $unknown[] = $payloadKey;
                continue;
            }

            if (DataObject::getSchema()->fieldSpec($linkClass, LinkTransformer::FIELD_MAP[$payloadKey]) === null) {
                $wrongType[] = $payloadKey;
            }
        }

        if ($unknown !== [] || $wrongType !== []) {
            $messages = [];

            if ($unknown !== []) {
                $messages[] = sprintf('unknown: %s', implode(', ', $unknown));
            }

            if ($wrongType !== []) {
                $messages[] = sprintf('not valid for type "%s": %s', $type, implode(', ', $wrongType));
            }

            throw new ApiError(
                ErrorCode::UNKNOWN_FIELD,
                sprintf(
                    'Link field(s) for "%s" %s. Valid keys: type, %s.',
                    $fieldName,
                    implode('; ', $messages),
                    implode(', ', array_keys(LinkTransformer::FIELD_MAP))
                )
            );
        }

        foreach (LinkTransformer::FIELD_MAP as $payloadKey => $linkField) {
            if (array_key_exists($payloadKey, $value)) {
                $link->setCastedField($linkField, $value[$payloadKey]);
            }
        }

        try {
            $link->write();
        } catch (ValidationException $exception) {
            throw ApiError::fromValidation($exception, sprintf('Link for "%s"', $fieldName));
        }

        if ($link->hasMethod('publishSingle')) {
            $link->publishSingle();
        }

        return (int) $link->ID;
    }
}
