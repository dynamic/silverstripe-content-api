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
 * `content_api_access`, and to the verbs that declaration lists. Both halves
 * are load-bearing:
 *
 * - Class scoping: `ClassRegistry::accessVerbs()` resolves
 *   `content_api_access` through an *inherited* Config lookup, so a project
 *   declaring it on `Page` also exposes every undeclared subclass at the
 *   class gate. A blanket record-level grant would therefore let a service
 *   account write and archive classes nobody intended to expose — confirmed
 *   live against commerce pages, UserForms, ErrorPage and RedirectorPage in a
 *   real project. This extension uses `ClassRegistry::ownAccessVerbs()`
 *   (`Config::UNINHERITED`) instead, so only a class that names itself gets
 *   a grant answer at all; every other subclass gets `null` and falls
 *   through to its normal permission checks, same as if this extension
 *   didn't exist.
 * - Verb scoping: `archive` is gated on the `delete` verb / canDelete(), not
 *   `action`/canEdit() (see #45) — this extension only grants canDelete()
 *   when the class's own declaration lists the delete verb, so a class that
 *   lists publish verbs but not DELETE never gets archive.
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
 * `update`/`action` verb. It's also load-bearing for archive on an
 * already-*published* record: `Versioned::canDelete()` independently vetoes
 * (returns `false`, in the same `extendedCan()` minimum as this extension's
 * own `canDelete()` answer) unless `canUnpublish()` succeeds, and
 * `canUnpublish()` falls through to `canPublish()` falls through to
 * `canEdit()`. A class declaring only the `delete` verb (no `update`/`action`)
 * can archive a draft-only record but not a published one.
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

    protected function canView($member = null)
    {
        return $this->grant('read', $member);
    }

    protected function canEdit($member = null)
    {
        return $this->grant(['update', 'action'], $member);
    }

    protected function canCreate($member = null, $context = [])
    {
        return $this->grant('create', $member);
    }

    protected function canDelete($member = null)
    {
        return $this->grant('delete', $member);
    }

    /**
     * @param string|string[] $verbs any one of these present in the owner's
     *   own declared verbs is enough to grant
     * @param mixed $member
     * @return true|null true to grant, null to abstain — never false, see
     *   the class docblock
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

        $registry = $this->registry ?: ClassRegistry::singleton();
        $declared = $registry->ownAccessVerbs(get_class($owner));

        foreach ((array) $verbs as $verb) {
            if (in_array($verb, $declared, true)) {
                return true;
            }
        }

        return null;
    }
}
