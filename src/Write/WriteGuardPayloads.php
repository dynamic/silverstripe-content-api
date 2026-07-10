<?php

namespace Dynamic\ContentApi\Write;

use SilverStripe\ORM\DataObject;
use WeakMap;

/**
 * Request-scoped registry mapping the exact DataObject colymba deserialized an
 * API payload into to that payload. Keyed by the owner object (a WeakMap) so
 * it scopes to precisely the record colymba targeted and auto-clears when that
 * record is garbage-collected — no cross-request leak, no spl_object_id reuse.
 *
 * Kept out of WriteGuardExtension itself: a static property on a Configurable
 * (Extension) class trips silverstan's configuration-property inference.
 */
final class WriteGuardPayloads
{
    private static ?WeakMap $map = null;

    private static function map(): WeakMap
    {
        return WriteGuardPayloads::$map ??= new WeakMap();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function store(DataObject $owner, array $payload): void
    {
        WriteGuardPayloads::map()[$owner] = $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(DataObject $owner): ?array
    {
        return WriteGuardPayloads::map()[$owner] ?? null;
    }
}
