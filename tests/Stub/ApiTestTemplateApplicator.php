<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Stand-in for `Dynamic\ElementalTemplates\Service\TemplateApplicator` (#174),
 * reachable via `PageHandler.template_applicator_class`.
 *
 * The load-bearing detail is the `$element->duplicate()` call: that is exactly
 * what the real `TemplateElementDuplicator::duplicateElements()` does, and
 * `DataObject::duplicate()` with no relation list falls back to
 * `$cascade_duplicates` — recursing into each copied child's own
 * `$cascade_duplicates` in turn. So the children this creates are created the
 * same way the real package creates them, which is the entire behavior under
 * test. A stub that attached hand-built children instead would prove nothing
 * about the real integration.
 *
 * Thinner than the real applicator everywhere else, and in three places it
 * deliberately does NOT match it. Do not write tests against these paths and
 * assume the answer transfers to production:
 *
 * - **Missing elemental area.** This returns `success => false`; the real
 *   applicator `write()`s the record to materialize an area and carries on,
 *   failing only if one still doesn't exist afterwards. The opposite outcome.
 * - **`setSkipPopulateData(true)`.** The real duplicator sets it before
 *   writing each copy, suppressing `populateElementData()`. That flag is what
 *   makes `duplicate()` the *only* creator of children on this path, which is
 *   in turn what lets the handler's walk be complete. This stub doesn't model
 *   it, so nothing here pins that assumption.
 * - **Per-element error handling.** The real duplicator wraps each element in
 *   `try/catch`, logs, and still reports success — a partially-failed apply
 *   returns 200 in production. Exceptions escape this stub instead.
 *
 * Re-reading the area from the page afterwards (rather than trusting a report
 * of what was written) is `PageHandler`'s answer to that last one.
 */
class ApiTestTemplateApplicator implements TestOnly
{
    /**
     * @return array{success: bool, message: string}
     */
    public function applyTemplateToRecord(DataObject $record, DataObject $template): array
    {
        $targetArea = $record->ElementalArea();
        $sourceArea = $template->Elements();

        if (!$targetArea->exists() || !$sourceArea->exists()) {
            return ['success' => false, 'message' => 'No elemental area.'];
        }

        $sort = (int) $targetArea->Elements()->max('Sort');

        foreach ($sourceArea->Elements()->sort('Sort') as $element) {
            $copy = $element->duplicate();
            $copy->Sort = ++$sort;
            $copy->ParentID = $targetArea->ID;
            $copy->write();

            if ($copy->hasExtension(Versioned::class)) {
                $copy->writeToStage(Versioned::DRAFT);
            }
        }

        return ['success' => true, 'message' => 'Template applied.'];
    }
}
