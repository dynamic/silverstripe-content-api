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
     * @param string[] $translatedRelations which polymorphic has_one
     *   relations translatePolymorphicHasOnes() actually resolved — the
     *   FK+Class pair may only be treated as writable-as-a-unit for a
     *   relation named here. A bare {Name}Class key that reaches
     *   onBeforeWrite() without its relation appearing in this set did not
     *   come from a genuine {"class","id"} resolution and must never be
     *   independently writable, regardless of allowlist config (#25's own
     *   hole, reopened on this surface if that distinction isn't kept).
     */
    public static function store(DataObject $owner, array $payload, array $translatedRelations = []): void
    {
        WriteGuardPayloads::map()[$owner] = [
            'payload' => $payload,
            'translatedRelations' => $translatedRelations,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(DataObject $owner): ?array
    {
        return WriteGuardPayloads::map()[$owner]['payload'] ?? null;
    }

    /**
     * @return string[]
     */
    public static function translatedRelations(DataObject $owner): array
    {
        return WriteGuardPayloads::map()[$owner]['translatedRelations'] ?? [];
    }
}
