<?php

namespace Dynamic\ContentApi\Write;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;

/**
 * Field-level write guard for colymba/silverstripe-restfulapi's generic
 * write path (POST/PUT /api/$Model), which natively applies EVERY key in the
 * JSON payload. Productized from project-feedback's ApiFieldGuardExtension.
 *
 * Config keys (shared vocabulary with WriteApplicator):
 * - `api_writable_fields`: on THIS untrusted colymba surface a non-empty list
 *   is an allowlist — only listed fields may change. (The trusted population
 *   path — batch/compositions — is not restricted by this key; it stays
 *   guarded so it can write structural fields like ParentID/Sort. Use
 *   `api_write_policy: allowlist` or the global `WriteApplicator.policy` to
 *   restrict that path too — both are honoured here.)
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

        WriteGuardPayloads::store($this->getOwner(), $body);

        if ($filtered) {
            $rawJson = json_encode($body) ?: $rawJson;
        }
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

        // Allowlist mode on this (untrusted) surface: the resolved policy
        // (per-class api_write_policy, else the global WriteApplicator.policy —
        // both honoured) OR simply a non-empty api_writable_fields. The trusted
        // population path handles the first two identically but ignores a bare
        // api_writable_fields (see WriteApplicator::isWritable).
        $policy = $owner->config()->get('api_write_policy')
            ?: (string) Config::inst()->get(WriteApplicator::class, 'policy');
        $allowlist = (array) $owner->config()->get('api_writable_fields');
        $allowlistMode = $policy === 'allowlist' || $allowlist !== [];

        $protected = array_merge(
            (array) Config::inst()->get(WriteApplicator::class, 'protected_fields'),
            (array) $owner->config()->get('api_protected_fields')
        );

        $hasOne = (array) $owner->config()->get('has_one');
        $changed = $owner->getChangedFields(true, 2);

        foreach (array_keys($body) as $attribute) {
            // Resolve the payload key to the DB column colymba actually wrote
            // (the FK column for a has_one) plus every name that could name it
            // in config: the payload key, the relation name and the FK column.
            if (array_key_exists($attribute, $hasOne)) {
                $column = $attribute . 'ID';
                $names = [$attribute, $column];
            } elseif (str_ends_with($attribute, 'ID') && isset($hasOne[substr($attribute, 0, -2)])) {
                $column = $attribute;
                $names = [$attribute, substr($attribute, 0, -2)];
            } else {
                $column = $attribute;
                $names = [$attribute];
            }

            // Protected always wins, regardless of allowlist (parity with
            // WriteApplicator::isWritable), matched by relation name OR column.
            $isProtected = array_intersect($names, $protected) !== [];

            $denied = $isProtected
                || ($allowlistMode && array_intersect($names, $allowlist) === []);

            if ($denied && array_key_exists($column, $changed)) {
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
