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
 * Kept deliberately thinner than the real applicator elsewhere: its own
 * validation branches (missing template, record without an elemental area)
 * return `['success' => false, ...]`, which `PageHandler` already maps to
 * VALIDATION_FAILED and which no part of this fix touches.
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
