<?php

namespace Dynamic\ContentApi\Write;

use SilverStripe\ORM\DataObject;

/**
 * Transforms a payload field value before it is applied to a record — the
 * extension point for value DSLs (color tokens, link payloads, asset refs).
 *
 * Register implementations on WriteApplicator:
 *
 * ```yml
 * Dynamic\ContentApi\Write\WriteApplicator:
 *   transformers:
 *     - '%$My\Project\MyTransformer'
 * ```
 */
interface ValueTransformer
{
    /**
     * Whether this transformer wants to handle the value.
     */
    public function supports(DataObject $record, string $fieldName, mixed $value): bool;

    /**
     * Return the transformed value. May throw ApiError (e.g.
     * TOKEN_RESOLUTION_FAILED) for unresolvable input.
     */
    public function transform(DataObject $record, string $fieldName, mixed $value): mixed;
}
