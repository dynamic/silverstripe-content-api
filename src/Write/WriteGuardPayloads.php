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

    /**
     * Which polymorphic has_one relations translatePolymorphicHasOnes()
     * actually resolved for this owner — the FK+Class pair may only be
     * treated as writable-as-a-unit for a relation named here. A bare
     * {Name}Class key that reaches onBeforeWrite() without its relation
     * appearing in this set did not come from a genuine {"class","id"}
     * resolution and must never be independently writable, regardless of
     * allowlist config (#25's own hole, reopened on this surface if that
     * distinction isn't kept).
     */
    private static ?WeakMap $translatedRelations = null;

    private static function map(): WeakMap
    {
        return WriteGuardPayloads::$map ??= new WeakMap();
    }

    private static function translatedRelationsMap(): WeakMap
    {
        return WriteGuardPayloads::$translatedRelations ??= new WeakMap();
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

    /**
     * @param string[] $relationNames
     */
    public static function storeTranslatedRelations(DataObject $owner, array $relationNames): void
    {
        WriteGuardPayloads::translatedRelationsMap()[$owner] = $relationNames;
    }

    /**
     * @return string[]
     */
    public static function translatedRelations(DataObject $owner): array
    {
        return WriteGuardPayloads::translatedRelationsMap()[$owner] ?? [];
    }
}
