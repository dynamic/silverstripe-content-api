<?php

namespace Dynamic\ContentApi\Tests\Stub;

use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Stand-in for `Dynamic\ElementalTemplates\Models\Template` (#174).
 *
 * That package is `suggest`ed — absent from the environment this module's SS5
 * suite runs in, present in the SS6 testbed — so `apply-template`'s publish
 * behavior had no test coverage of any kind before this stub existed.
 * `PageHandler.template_class` points here for the duration of those tests,
 * regardless of whether the real package is installed, so the tests don't
 * vary with the environment.
 *
 * Mirrors only what the endpoint touches: a `has_one` `Elements` ElementalArea
 * holding the source elements to be duplicated onto a page. Field-for-field
 * fidelity with the real model isn't the point — what must match is that the
 * elements sitting in that area are real `BaseElement` records, so
 * {@see ApiTestTemplateApplicator} exercises the genuine framework
 * `duplicate()`/`$cascade_duplicates` cascade rather than a hand-built fake.
 */
class ApiTestTemplateModel extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestTemplateModel';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Elements' => ElementalArea::class,
    ];

    private static array $owns = [
        'Elements',
    ];

    private static array $extensions = [
        Versioned::class,
    ];

    public function canView($member = null): bool
    {
        return true;
    }
}
