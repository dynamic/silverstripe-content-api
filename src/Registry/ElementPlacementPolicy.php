<?php

namespace Dynamic\ContentApi\Registry;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Enforces Elemental's own per-page-type `allowed_elements`/
 * `disallowed_elements` config against writes this module performs — without
 * it, the content API can create element instances a CMS editor could never
 * create through the "add element" picker on that page type (#64).
 *
 * Deliberately delegates entirely to
 * `DNADesign\Elemental\Extensions\ElementalAreasExtension::getElementalTypes()`
 * — the CMS's own canonical check, used by `BaseElement::moveTo()` and the
 * CMS admin's own move endpoint — rather than reading `allowed_elements`/
 * `disallowed_elements` config directly. That method already handles
 * `stop_element_inheritance`, the per-element `canCreate()`/
 * `canCreateElement()` gate, `BaseElement` self-removal from the list, and
 * the `updateAvailableTypesForClass` extension hook; reimplementing any of
 * that here would risk silently drifting from what the CMS itself enforces.
 *
 * `disallowed_elements` is declared per page type (with inheritance), not
 * globally — this is why enforcement lives here (checked against the
 * specific page an element is being attached to) rather than in
 * `ClassRegistry`'s discovery pass, which has no concept of "which page."
 */
class ElementPlacementPolicy
{
    use Injectable;

    /**
     * `true` when `$page` doesn't support Elemental at all (nothing to
     * enforce — don't block a write that has nothing to do with this
     * policy) or when `$elementClass` is genuinely one of the page's
     * allowed types.
     */
    public function isAllowedOnPage(string $elementClass, DataObject $page): bool
    {
        if (!$page->hasMethod('getElementalTypes')) {
            return true;
        }

        return array_key_exists($elementClass, $page->getElementalTypes());
    }
}
