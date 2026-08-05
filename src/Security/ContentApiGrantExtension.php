<?php

namespace Dynamic\ContentApi\Security;

use Dynamic\ContentApi\Registry\ClassRegistry;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Grants record-level canView()/canEdit()/canCreate()/canDelete() to a
 * Member holding CONTENT_API_ACCESS, so a content-api service account can
 * write without a real CMS login (CMS_ACCESS_CMSMain, SITETREE_EDIT_ALL,
 * ADMIN). Without it, a model's own can*() methods fall through to
 * permissions a plain CONTENT_API_ACCESS holder can never satisfy, and the
 * only way to run a page-restructure batch is a temporary ADMIN grant on the
 * service account's group — this is the gap `SetupServiceAccountTask`'s own
 * output has always flagged as "application code this task can't inject."
 *
 * Opt-in per class, the same pattern as WriteGuardExtension:
 * ```yml
 * SilverStripe\CMS\Model\SiteTree:
 *   extensions:
 *     - Dynamic\ContentApi\Security\ContentApiGrantExtension
 * DNADesign\Elemental\Models\BaseElement:
 *   extensions:
 *     - Dynamic\ContentApi\Security\ContentApiGrantExtension
 * ```
 *
 * Apply it to `BaseElement` too if the service account needs to write
 * Elemental content (compositions/batch element writes), not just pages:
 * `BaseElement::canView()`/`canEdit()`/`canDelete()` delegate to the owning
 * page's own check, but `canCreate()` does NOT — it falls straight to
 * `Permission::check('CMS_ACCESS', 'any', $member)`, which a
 * `CONTENT_API_ACCESS`-only account can never satisfy. A grant on `SiteTree`
 * alone lets the account edit/publish/archive pages but never create an
 * element, even one nested under an opted-in page.
 *
 * SECURITY — the grant is scoped to classes that declare their OWN
 * `content_api_access` (or `api_access` — same precedence as
 * `ClassRegistry::accessVerbs()`), and to the verbs that declaration lists.
 * Both halves are load-bearing:
 *
 * - Class scoping: `ClassRegistry::accessVerbs()` resolves
 *   `content_api_access`/`api_access` through an *inherited* Config lookup,
 *   so a project declaring it on `Page` also exposes every undeclared
 *   subclass at the class gate. A blanket record-level grant would therefore
 *   let a service account write and archive classes nobody intended to
 *   expose — confirmed live against commerce pages, UserForms, ErrorPage and
 *   RedirectorPage in a real project. This extension uses
 *   `ClassRegistry::ownAccessVerbs()` (`Config::UNINHERITED |
 *   Config::EXCLUDE_EXTRA_SOURCES`) instead, so only a class whose own
 *   literal declaration (not an ancestor's, and not one contributed by
 *   another extension applied to it) names a verb gets a grant answer for
 *   that verb at all; every other subclass gets `null` and falls through to
 *   its normal permission checks, same as if this extension didn't exist.
 *   Residual scope this does NOT close: a class's own declaration is honoured
 *   even if that class was never registered in `ClassRegistry`'s exposure map
 *   (`models`/`discovery_roots`) — i.e. even if the content API itself could
 *   never route to it. `content_api_access` is module-specific, so only a
 *   project sets it deliberately; `api_access` is a legacy/colymba-era key
 *   third-party code can self-declare for unrelated reasons, so a vendor
 *   class that happens to declare it under a grant-applied ancestor gets a
 *   grant too. This mirrors `accessVerbs()`'s own existing trust model for
 *   HTTP exposure (this extension is strictly narrower — uninherited vs
 *   inherited — not broader), and is deliberate: the module's design
 *   principle throughout is that a class's own `can*()` methods are the real
 *   safety boundary, not map membership (see `ClassRegistry`'s own docblock).
 * - Verb scoping: `archive` is gated on the `delete` verb / canDelete(), not
 *   `action`/canEdit() (see #45) — this extension only grants canDelete()
 *   when the class's own declaration lists the delete verb, so a class that
 *   lists publish verbs but not DELETE never gets archive.
 *
 * Also note: this extension's grant is per concrete class, not inherited by
 * further subclasses via the record-level check. `File`/`Image` is a real
 * example — a project following the documented "`Image` inherits `File`'s
 * `api_access`" convention gets `canCreate()` granted (checked against
 * `File` itself), but a later `canEdit()`/`canDelete()` on the created
 * `Image` record checks `Image`'s own uninherited declaration, which is
 * empty, and falls through to `FILE_EDIT_ALL` — a permission a
 * `CONTENT_API_ACCESS`-only account doesn't hold. Declare
 * `content_api_access`/`api_access` on every concrete class a service
 * account needs to write, not just the ancestor it inherits HTTP exposure
 * from.
 *
 * Every method returns `true` or `null`, never `false`:
 * `DataObject::extendedCan()` takes the minimum of every extension's answer
 * for a hook, so a `false` here would deny that permission for every other
 * member and extension too, sitewide — including real CMS editors.
 *
 * `canEdit()`'s grant is transitive by design on `SiteTree`:
 * `SiteTree::canPublish()` and `canAddChildren()` both fall through to
 * `canEdit()` when nothing answers them directly, which is what makes
 * publish/unpublish and reparenting work for a service account holding the
 * `update`/`action` verb.
 *
 * BRANCH NOTE: on branch `1`, `canEdit()`'s grant is *also* load-bearing for
 * archiving an already-*published* record — `Versioned::canDelete()` there
 * independently vetoes (returns `false`, in the same `extendedCan()` minimum
 * as this extension's own `canDelete()` answer) unless `canUnpublish()`
 * succeeds, which falls through to `canPublish()` falls through to
 * `canEdit()`. **That veto does not exist on this branch's
 * `silverstripe/versioned` (confirmed against a real SS5.2 install,
 * `2.4.x-dev`) — `Versioned` has no `canDelete()` override at all here, only
 * a deprecated `canArchive()` whose own docblock says to use `canDelete()`
 * instead on the version branch `1` depends on.** A class declaring only the
 * `delete` verb (no `update`/`action`) can archive both a draft-only record
 * AND an already-published one on this branch — see
 * `ContentApiGrantExtensionTest::testCanDeleteOnAPublishedRecordDoesNotNeedTheEditGrantHere()`.
 *
 * `canView()` alone does NOT make a draft-only record readable:
 * `Versioned::canViewVersioned()` answers `false` once draft and live
 * diverge, and that `false` participates in the same `extendedCan()`
 * minimum, overriding this extension's `true`. `VIEW_DRAFT_CONTENT` is what
 * satisfies Versioned's check — `SetupContentApiServiceAccount` already
 * grants it alongside `CONTENT_API_ACCESS`. Don't "fix" a draft-read 403 by
 * widening this extension; check the account holds `VIEW_DRAFT_CONTENT`
 * instead. Conversely, on a live-stage read (the normal front-end browsing
 * path) this extension's `canView()` grant is the sole answer for an opted-in
 * class, so any `CanViewType` restriction on that class does not apply to a
 * `CONTENT_API_ACCESS` holder.
 *
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class ContentApiGrantExtension extends Extension
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ClassRegistry $registry = null;

    protected function canView($member = null): ?bool
    {
        return $this->grant('read', $member);
    }

    protected function canEdit($member = null): ?bool
    {
        return $this->grant(['update', 'action'], $member);
    }

    protected function canCreate($member = null, $context = []): ?bool
    {
        return $this->grant('create', $member);
    }

    protected function canDelete($member = null): ?bool
    {
        return $this->grant('delete', $member);
    }

    /**
     * @param string|string[] $verbs any one of these present in the owner's
     *   own declared verbs is enough to grant
     * @param mixed $member
     * @return true|null true to grant, null to abstain — never false, see
     *   the class docblock. Typed `?bool` rather than `true|null` on this
     *   branch: standalone `true` as a type is PHP 8.2+, and this branch's
     *   floor is PHP `^8.1` — branch `1` (PHP `^8.3`) enforces the invariant
     *   at the type level; here it's convention plus the regression test.
     */
    protected function grant(string|array $verbs, $member = null): ?bool
    {
        $member = $member ?: Security::getCurrentUser();

        if (!$member || !Permission::checkMember($member, ContentApiPermissions::ACCESS)) {
            return null;
        }

        $owner = $this->getOwner();

        if (!$owner) {
            return null;
        }

        $declared = $this->registry->ownAccessVerbs(get_class($owner));

        foreach ((array) $verbs as $verb) {
            if (in_array($verb, $declared, true)) {
                return true;
            }
        }

        return null;
    }
}
