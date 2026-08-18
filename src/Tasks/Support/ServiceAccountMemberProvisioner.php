<?php

namespace Dynamic\ContentApi\Tasks\Support;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Find-or-create the `Member` a content API service account authenticates
 * as, and attach it to an already-provisioned permission group (#124).
 *
 * `ServiceAccountProvisioner` only provisions the Group + permission codes;
 * `ApiTokenMinter` explicitly requires a `Member` to already exist and be
 * attached to that group. Nothing in the module bridged "create-or-find a
 * Member and attach it to the service-account group" before this — every
 * project needing a service account had to write this find-or-create block
 * itself, and re-run it after every DB sync (a locally-created service
 * account Member isn't part of a synced prod snapshot).
 *
 * Deliberately a separate, explicitly opt-in step from
 * `ServiceAccountProvisioner::provision()`, not folded into it: creating a
 * Member is a different kind of action than provisioning a permission
 * group, and an operator should have to name it (`--member` on
 * `SetupServiceAccountTask`) rather than have a group-provisioning run
 * silently mint a login-capable account as a side effect. `ApiTokenMinter`
 * itself is intentionally untouched — its "No member found with email ..."
 * failure is what protects against a typo'd `--email` silently minting a
 * token for a brand-new account; that protection stays in place for the
 * mint step. This class is the only place a new Member gets created, and
 * only when explicitly asked for.
 *
 * A newly-created Member gets an explicit random password
 * (`generateRandomPassword()`, generated and discarded, never printed) —
 * NOT left unset. A first draft of this class relied on `Member::write()`
 * to "auto-generate a random, unknown" password when none is set; that
 * claim was wrong, caught in review before merge: `Member::onBeforeWrite()`
 * only re-encrypts when `!$this->ID || isChanged('Password')`, and on a
 * brand-new record with `Password` never touched, that encrypts the literal
 * empty string — a *known*, not unknown, credential. `Member_GroupSet`'s
 * form-layer `RequiredFields` guard stops the CMS login form from ever
 * submitting one, but that's a form guard, not an authenticator guard —
 * any code calling `MemberAuthenticator::checkPassword()` directly with an
 * empty password would succeed. Setting a real random password removes
 * that gap outright rather than relying on believing a false safety claim.
 *
 * The group this member is attached to is checked for `ADMIN` or any
 * `CMS_ACCESS_*` code — on the group itself AND every ancestor
 * (`Group::collateAncestorIDs()`; group titles resolve case-insensitively,
 * and membership inherits upward through the group tree, so attaching to a
 * child of a privileged group inherits that privilege) — before this task
 * tells the operator the account has no CMS-admin access. That reassurance
 * is only ever true about the specific permission codes
 * `ServiceAccountProvisioner` itself grants (`CONTENT_API_ACCESS`,
 * `CONTENT_API_POPULATE`, `VIEW_DRAFT_CONTENT`, none of which is
 * `CMS_ACCESS_*`) — it says nothing about what the *resolved group* already
 * carried before this task ever ran, and `group=Administrators` (or a group
 * whose ancestor is) is a real, reachable input, not a hypothetical one.
 *
 * Idempotent, matching `ServiceAccountProvisioner`'s own contract: a
 * re-run finds the existing Member by email rather than duplicating it,
 * never detaches it from a group it's already in, and never touches its
 * password (the existing-member branch never calls `write()` at all).
 *
 * Branch-neutral: both branch `2`'s SS6 `SetupServiceAccountTask` adapter
 * and branch `1`'s legacy adapter call this directly, same pattern as
 * `ServiceAccountProvisioner`/`ApiTokenMinter` — see #65/#96.
 */
class ServiceAccountMemberProvisioner
{
    use Injectable;

    public function provision(string $email, string $groupTitle): TaskResult
    {
        $email = trim($email);

        if ($email === '') {
            return new TaskResult(TaskStatus::Invalid, ['--member cannot be empty.']);
        }

        $title = trim($groupTitle);
        $groups = Group::get()->filter('Title', $title);

        if ($groups->count() > 1) {
            return new TaskResult(TaskStatus::Failure, [sprintf(
                'Multiple groups titled "%s" found (IDs: %s) — refusing to guess which one to '
                    . 'attach the member to. Disambiguate by renaming or deleting the unintended '
                    . 'group, then re-run.',
                $title,
                implode(', ', $groups->column('ID'))
            )]);
        }

        $group = $groups->first();

        if (!$group) {
            return new TaskResult(TaskStatus::Failure, [sprintf(
                'No group titled "%s" exists yet — run the group-provisioning step first.',
                $title
            )]);
        }

        $lines = [];
        $member = Member::get()->filter('Email', $email)->first();

        if (!$member) {
            $member = Member::create();
            $member->Email = $email;
            // Never left unset — see the class docblock for why an unset
            // Password on a new record encrypts the empty string, a known
            // credential, not an unknown one.
            $member->Password = $member->generateRandomPassword();
            $member->write();
            $lines[] = "Created member \"{$email}\" (#{$member->ID}).";
        } else {
            $lines[] = "Using existing member \"{$email}\" (#{$member->ID}).";
        }

        if ($member->inGroup($group)) {
            $lines[] = "  already in group \"{$title}\" (#{$group->ID}).";
        } else {
            $member->Groups()->add($group);
            $lines[] = "  added to group \"{$title}\" (#{$group->ID}).";
        }

        $lines[] = '';

        if ($this->groupOrAncestorGrantsCmsAccess($group)) {
            $lines[] = 'WARNING: group "' . $title . '" (or an ancestor group) already grants ADMIN '
                . 'or a CMS_ACCESS_* permission code — this member has real CMS-admin access, not '
                . 'just the content-api-scoped grants above. Confirm that is intended before minting '
                . 'a token for it.';
        } else {
            $lines[] = 'This member has no CMS-admin access (checked group "' . $title . '" and its '
                . 'ancestors — none currently grants ADMIN or a CMS_ACCESS_* code) — token auth only.';
        }

        return new TaskResult(TaskStatus::Success, $lines);
    }

    /**
     * Whether `$group` — or any ancestor, since group membership grants
     * inherit upward through the tree (`Member_GroupSet::foreignIDFilter()`)
     * — carries `ADMIN` (which implies every permission, including CMS
     * access) or any `CMS_ACCESS_*` code. `collateAncestorIDs()` already
     * includes the group's own ID, so a plain (non-hierarchical) group is
     * covered by the same query. Same `CMS_ACCESS_` prefix convention
     * `Permission::checkMember()` itself uses.
     */
    private function groupOrAncestorGrantsCmsAccess(Group $group): bool
    {
        $codes = Permission::get()
            ->filter('GroupID', $group->collateAncestorIDs())
            ->column('Code');

        foreach ($codes as $code) {
            if ($code === 'ADMIN' || str_starts_with((string) $code, 'CMS_ACCESS_')) {
                return true;
            }
        }

        return false;
    }
}
