<?php

namespace Dynamic\ContentApi\Write;

use Dynamic\ContentApi\Errors\ApiError;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

/**
 * Field-level write guard for colymba/silverstripe-restfulapi's generic
 * write path (POST/PUT /api/$Model), which natively applies EVERY key in the
 * JSON payload. Productized from project-feedback's ApiFieldGuardExtension.
 *
 * Config keys (shared vocabulary with WriteApplicator):
 * - `api_writable_fields`: a non-empty list is an allowlist — only listed
 *   fields may change. Identical on every write surface: this untrusted
 *   colymba surface AND the trusted population path (batch/compositions)
 *   both honour a bare `api_writable_fields` as the opt-in into allowlist
 *   mode (no separate `api_write_policy: allowlist` needed on either side —
 *   that key only matters for a class with an empty `api_writable_fields`
 *   that still wants allowlist-by-default semantics).
 * - `api_protected_fields` + global WriteApplicator `protected_fields`:
 *   never writable, and always win over the allowlist.
 * - `api_writable_relations`: has_many/many_many keys not listed are
 *   stripped from the payload before colymba applies them (its relation
 *   handling is removeAll()+add() straight to the DB, invisible to
 *   onBeforeWrite — so a GET-then-PUT-verbatim round trip would otherwise
 *   detach relations)
 *
 * Scoped strictly to writes running under colymba's RESTfulAPI controller:
 * CMS writes and this module's own WriteApplicator path are never touched.
 *
 * Opt-in per model:
 * ```yml
 * DNADesign\Elemental\Models\ElementContent:
 *   api_access: 'GET,POST,PUT,action'
 *   api_writable_fields: [Title, HTML, Sort]
 *   extensions:
 *     - Dynamic\ContentApi\Write\WriteGuardExtension
 * ```
 * `action` (publish/unpublish/archive) has no HTTP method of its own — it
 * must be listed as a bare token, not implied by GET/POST/PUT, or the class
 * can never actually publish a write through this API (#198).
 *
 * SECURITY: never grant write verbs in `api_access` without either this
 * extension or an explicit trusted-caller decision.
 *
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class WriteGuardExtension extends Extension
{
    /**
     * colymba broadcasts this with the raw JSON before deserializing.
     * Strip many-relation keys that are not writable.
     *
     * The payload is recorded in WriteGuardPayloads keyed by the owner object,
     * not on this extension instance: extension instances are effectively
     * shared per class, so an instance property would leak the target's
     * payload onto sibling/cascade writes of the same class.
     *
     * @param string $rawJson passed by reference through extend()
     */
    protected function onBeforeDeserialize(&$rawJson): void
    {
        if (!$this->isColymbaApiWrite()) {
            return;
        }

        $body = json_decode((string) $rawJson, true);

        if (!is_array($body)) {
            return;
        }

        $writableRelations = (array) $this->getOwner()->config()->get('api_writable_relations');
        $manyRelations = array_keys(array_merge(
            (array) $this->getOwner()->config()->get('has_many'),
            (array) $this->getOwner()->config()->get('many_many'),
            (array) $this->getOwner()->config()->get('belongs_many_many')
        ));

        $filtered = false;

        foreach ($manyRelations as $relation) {
            if (array_key_exists($relation, $body) && !in_array($relation, $writableRelations, true)) {
                unset($body[$relation]);
                $filtered = true;
            }
        }

        $translatedRelations = $this->translatePolymorphicHasOnes($body);
        $filtered = $translatedRelations !== [] || $filtered;

        WriteGuardPayloads::store($this->getOwner(), $body, $translatedRelations);

        if ($filtered) {
            $encoded = json_encode($body);

            if ($encoded === false) {
                // A guard that can silently no-op on an encoding failure is
                // worse than one that errors loudly (#22) — falling back to
                // $rawJson here would mean colymba deserializes the
                // *original, unstripped* payload, silently bypassing the
                // relation-key strip this method just computed.
                //
                // HTTPResponse_Exception specifically: this hook runs inside
                // colymba's own controller action, which has no exception
                // handling of its own — but SilverStripe's RequestHandler
                // catches HTTPResponse_Exception and turns it into the
                // response, so this still fails as a clean HTTP error
                // instead of an unhandled exception. The internal class name
                // stays in the log, not the client-facing message.
                Injector::inst()->get(LoggerInterface::class)->error(sprintf(
                    'WriteGuardExtension could not re-encode the filtered payload for %s (json_encode: %s).',
                    get_class($this->getOwner()),
                    json_last_error_msg()
                ));

                throw new HTTPResponse_Exception('Unable to process the request payload.', 500);
            }

            $rawJson = $encoded;
        }
    }

    /**
     * Colymba's deserializer writes raw column values straight to the model
     * — it has no concept of this module's `{"class": "...", "id": n}`
     * has_one payload shape, or the companion `{Name}Class` column a
     * polymorphic has_one needs alongside its FK. Left alone, a polymorphic
     * has_one has no working way to be set through colymba's native
     * surface at all (#23).
     *
     * Translates any polymorphic has_one entries in the payload into the
     * raw `{Name}ID`/`{Name}Class` columns colymba already knows how to
     * write, using the exact same resolution
     * `WriteApplicator::resolveRelation()` applies on this module's own
     * write path — one resolver, not a second implementation (#24).
     *
     * Only translates (and only resolves — see below) a relation that's
     * actually writable: resolution can itself throw (a bad/missing class
     * hint, an unresolvable externalId), and running it before checking
     * writability would turn "you can't write this relation, so it's
     * silently reverted" into a hard validation error for a field the
     * client was never allowed to touch in the first place. A relation
     * that fails this check is left completely untouched in the payload —
     * colymba's deserializer won't find a matching column for the bare
     * relation-name key, so nothing is written, matching the intended
     * silent-no-op outcome.
     *
     * @param array<string, mixed> $body passed by reference — mutated in place
     * @return string[] relation names actually translated — onBeforeWrite()
     *   may only treat a relation's FK+Class pair as writable-as-a-unit
     *   when it's named here; a bare {Name}Class key that reaches
     *   onBeforeWrite() without having been translated here did not come
     *   from a genuine {"class","id"} resolution and must never be
     *   independently writable (closes the same hole #25 closed on this
     *   module's own write path, for this surface too).
     * @throws HTTPResponse_Exception wrapping an ApiError from resolution:
     *   thrown as HTTPResponse_Exception rather than left as ApiError
     *   because this hook has no exception handling of its own to catch a
     *   bare ApiError.
     */
    private function translatePolymorphicHasOnes(array &$body): array
    {
        $owner = $this->getOwner();
        $className = get_class($owner);
        $hasOne = (array) $owner->hasOne();
        $applicator = Injector::inst()->get(WriteApplicator::class);
        $translated = [];

        foreach ($hasOne as $relationName => $relationClass) {
            if ($relationClass !== DataObject::class || !array_key_exists($relationName, $body)) {
                continue;
            }

            if (!$applicator->isPolymorphicRelationWritable($className, $relationName)) {
                continue;
            }

            try {
                $resolved = $applicator->resolveRelation($relationName, $relationClass, $body[$relationName]);
            } catch (ApiError $error) {
                throw new HTTPResponse_Exception($error->getMessage(), $error->getStatus());
            }

            unset($body[$relationName]);
            $body[$relationName . 'ID'] = $resolved['id'];
            $body[$relationName . 'Class'] = $resolved['class'];
            $translated[] = $relationName;
        }

        return $translated;
    }

    /**
     * Revert payload-named fields that violate the write policy. Only keys
     * named in the payload are considered — sibling extensions and the
     * model's own onBeforeWrite may legitimately change other fields.
     */
    protected function onBeforeWrite(): void
    {
        if (!$this->isColymbaApiWrite()) {
            return;
        }

        $owner = $this->getOwner();

        // Only the exact record colymba deserialized the payload into is in
        // the map. A miss means this write is a cascade/sibling write during
        // the API request (e.g. a Sort renumber in the target's onAfterWrite)
        // — NOT the API-targeted record. Applying the target's field policy to
        // it would silently revert legitimate programmatic changes.
        $body = WriteGuardPayloads::get($owner);

        if (!is_array($body)) {
            return;
        }

        $className = get_class($owner);
        $hasOne = (array) $owner->hasOne();
        $changed = $owner->getChangedFields(true, 2);
        $applicator = Injector::inst()->get(WriteApplicator::class);
        $translatedRelations = WriteGuardPayloads::translatedRelations($owner);

        // A polymorphic has_one's FK and companion Class column must be
        // reverted together, not independently, for a relation
        // translatePolymorphicHasOnes() actually resolved (both columns
        // are then guaranteed present together) — checking each column's
        // writability separately there would let an asymmetric allowlist
        // (e.g. protecting OwnerClass but allowing Owner) leave the FK
        // pointing at a real record while the Class column reverts,
        // corrupting the relation rather than just narrowing what's
        // writable.
        //
        // A relation NOT in this set must not be paired: a request that
        // legitimately writes only the FK (e.g. repointing an
        // already-typed relation to a different record of the same class,
        // without re-sending the class) must not be blocked just because
        // the untouched Class column happens to be protected.
        $polymorphicWritable = [];

        // A relation only ever lands in $translatedRelations after
        // translatePolymorphicHasOnes() already confirmed
        // isPolymorphicRelationWritable() for it moments earlier in this
        // same request — that answer cannot have changed since, so it's
        // reused verbatim rather than recomputed.
        foreach ($translatedRelations as $relationName) {
            $polymorphicWritable[$relationName . 'ID'] = true;
            $polymorphicWritable[$relationName . 'Class'] = true;
        }

        // A relation NOT in $translatedRelations can still have both its
        // raw {Name}ID and {Name}Class keys sent directly together,
        // bypassing the wrapped {"class","id"} shape
        // translatePolymorphicHasOnes() expects. The Class column can
        // never be legitimately set this way (see the unconditional revert
        // below), so if the *client's payload* actually changes the Class
        // column, revert the FK alongside it rather than letting the FK
        // half through — applying just the FK half of an untranslated pair
        // produces exactly the torn FK-repointed/Class-stale state this
        // mechanism exists to prevent.
        //
        // Both conditions matter, for different reasons:
        // - Requiring the key in $body (not just $changed) keeps this
        //   scoped to what THIS request's payload actually named — this
        //   method's own contract is "only keys named in the payload are
        //   considered"; a sibling extension/hook mutating the Class
        //   column for unrelated reasons must not trigger a revert of a
        //   field the client's payload never touched.
        // - Requiring the value in $changed (not just $body) handles the
        //   routine GET-then-PUT-verbatim round trip, where the Class
        //   column is echoed back unchanged alongside a legitimate FK
        //   repoint — that must not be treated as a bypass attempt.
        //
        // A bare FK key with no Class key in the payload at all is
        // unaffected either way, falling through to the normal independent
        // check below.
        foreach ($hasOne as $relationName => $relationClass) {
            if ($relationClass !== DataObject::class || in_array($relationName, $translatedRelations, true)) {
                continue;
            }

            $classKey = $relationName . 'Class';

            if (array_key_exists($classKey, $body) && array_key_exists($classKey, $changed)) {
                $polymorphicWritable[$relationName . 'ID'] = false;
            }
        }

        foreach (array_keys($body) as $attribute) {
            $polymorphicClassRelation = $applicator->polymorphicClassColumnRelation($attribute, $hasOne);
            $polymorphicMultiRelation = $applicator->polymorphicRelationColumnRelation(
                $attribute,
                $className,
                $hasOne
            );

            // A multirelational polymorphic has_one's companion
            // {Name}Relation column disambiguates which reciprocal has_many
            // a record belongs to — nothing on this surface (or the
            // module's own pipeline) ever legitimately resolves a value
            // for it, so it's always reverted if colymba's deserializer
            // wrote it. #34.
            if ($polymorphicMultiRelation !== null) {
                if (array_key_exists($attribute, $changed)) {
                    $owner->setField($attribute, $changed[$attribute]['before']);
                }
                continue;
            }

            // Resolve the payload key to the DB column colymba actually wrote
            // (the FK column for a has_one) and the relation name, so the
            // shared writability check matches protected/allowlist config by
            // either name.
            if (array_key_exists($attribute, $hasOne)) {
                $column = $attribute . 'ID';
                $relationName = $attribute;
            } elseif (str_ends_with($attribute, 'ID') && isset($hasOne[substr($attribute, 0, -2)])) {
                $column = $attribute;
                $relationName = substr($attribute, 0, -2);
            } elseif ($polymorphicClassRelation !== null) {
                $column = $attribute;
                $relationName = $polymorphicClassRelation;
            } else {
                $column = $attribute;
                $relationName = null;
            }

            // A Class column that did NOT come from a genuine translated
            // {"class","id"} resolution — e.g. a client sending a bare
            // {Name}Class key directly, with no paired {Name}/{Name}ID —
            // is never independently writable, regardless of allowlist
            // config. This is the same rule
            // WriteApplicator::applyFields() enforces unconditionally on
            // the module's own write path (#25); without it here, a raw
            // Class column write would resolve to its relation's allowlist
            // entry via $relationName above and be judged writable with no
            // ClassRegistry validation at all.
            $isUntranslatedClassColumn = $polymorphicClassRelation !== null
                && !in_array($polymorphicClassRelation, $translatedRelations, true);

            if ($isUntranslatedClassColumn) {
                if (array_key_exists($column, $changed)) {
                    $owner->setField($column, $changed[$column]['before']);
                }
                continue;
            }

            // Delegate to the single writability source of truth, except
            // for a translated polymorphic relation's pair, which share
            // the precomputed combined decision above.
            $writable = $polymorphicWritable[$column]
                ?? $applicator->isFieldWritable($className, $column, $relationName);

            // A plain (non-relation) column colymba's deserializer already
            // wrote straight to the model gets no value validation from
            // colymba itself — writability above only answers "may this
            // field change at all," never "is the new value valid." An
            // Enum-backed column accepted an out-of-list value here the
            // same way `WriteApplicator::applyFields()` used to (#confirmed
            // live, 46 elements, essentials project) — silently MySQL-
            // coerced to '' — on this surface too, since it never routes
            // through `applyFields()` at all. Reverted the same way an
            // unwritable field already is (colymba's controller has no
            // clean way to reject mid-write with this API's structured
            // error shape), rather than left as a second, inconsistent
            // failure mode on this one surface.
            $invalidValue = $relationName === null
                && array_key_exists($column, $changed)
                && !$applicator->isEnumValueAcceptable($owner, $column, $changed[$column]['after']);

            if (($invalidValue || !$writable) && array_key_exists($column, $changed)) {
                $owner->setField($column, $changed[$column]['before']);
            }
        }
    }

    /**
     * Only guard writes flowing through colymba's controller — never the
     * CMS, never this module's own WriteApplicator pipeline.
     */
    private function isColymbaApiWrite(): bool
    {
        // SS6: Controller::curr() is nullable (has_curr() was removed).
        return class_exists('Colymba\\RESTfulAPI\\RESTfulAPI')
            && Controller::curr() instanceof \Colymba\RESTfulAPI\RESTfulAPI;
    }
}
