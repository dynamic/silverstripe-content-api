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
 *   api_access: 'GET,POST,PUT'
 *   api_writable_fields: [Title, HTML, Sort]
 *   extensions:
 *     - Dynamic\ContentApi\Write\WriteGuardExtension
 * ```
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

        $filtered = $this->translatePolymorphicHasOnes($body) || $filtered;

        WriteGuardPayloads::store($this->getOwner(), $body);

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
     * @param array<string, mixed> $body passed by reference — mutated in place
     * @throws HTTPResponse_Exception wrapping an ApiError from resolution
     *   (a bad/missing class hint, an unresolvable externalId): thrown as
     *   HTTPResponse_Exception rather than left as ApiError because this
     *   hook has no exception handling of its own to catch a bare ApiError.
     */
    private function translatePolymorphicHasOnes(array &$body): bool
    {
        $owner = $this->getOwner();
        $hasOne = (array) $owner->config()->get('has_one');
        $applicator = Injector::inst()->get(WriteApplicator::class);
        $translated = false;

        foreach ($hasOne as $relationName => $relationClass) {
            if ($relationClass !== DataObject::class || !array_key_exists($relationName, $body)) {
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
            $translated = true;
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
        $hasOne = (array) $owner->config()->get('has_one');
        $changed = $owner->getChangedFields(true, 2);
        $applicator = Injector::inst()->get(WriteApplicator::class);

        foreach (array_keys($body) as $attribute) {
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
            } elseif (
                str_ends_with($attribute, 'Class')
                && ($hasOne[substr($attribute, 0, -5)] ?? null) === DataObject::class
            ) {
                // The companion Class column translatePolymorphicHasOnes()
                // wrote alongside the FK — gate it via the same relation's
                // allowlist entry, not as an unrelated standalone field
                // (#25, on this surface too).
                $column = $attribute;
                $relationName = substr($attribute, 0, -5);
            } else {
                $column = $attribute;
                $relationName = null;
            }

            // Delegate to the single writability source of truth.
            $writable = $applicator->isFieldWritable($className, $column, $relationName);

            if (!$writable && array_key_exists($column, $changed)) {
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
