<?php

namespace Dynamic\ContentApi\Tasks\Support;

use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Core\Injector\Injectable;

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
 * A Member provisioned here has no CMS-admin access by default: the
 * permission codes `ServiceAccountProvisioner` grants (`CONTENT_API_ACCESS`,
 * `CONTENT_API_POPULATE`, `VIEW_DRAFT_CONTENT`) don't include any
 * `CMS_ACCESS_*` code, so SilverStripe's own admin UI denies it every
 * section regardless of this class's own behavior — no separate lockout
 * logic needed here. This class never sets a password itself; `Member::
 * write()` auto-generates a random, unknown one when none is set (confirmed
 * live), which is functionally equivalent for lockout purposes even though
 * it isn't literally empty — nobody, including this task's operator, knows
 * it, so a normal password login is unreachable in practice too.
 *
 * Idempotent, matching `ServiceAccountProvisioner`'s own contract: a
 * re-run finds the existing Member by email rather than duplicating it,
 * never detaches it from a group it's already in, and never touches a
 * password (there isn't one to reset).
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
        $lines[] = 'This member has no CMS-admin access (the permission codes granted above '
            . 'carry no CMS_ACCESS_* code) and no known password (SilverStripe auto-generates '
            . 'an unknown one) — token auth only.';

        return new TaskResult(TaskStatus::Success, $lines);
    }
}
