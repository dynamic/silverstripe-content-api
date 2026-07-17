<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

/**
 * Provision (or update) the permission Group a content API service account
 * needs. Idempotent — re-running only adds missing grants, never duplicates
 * or removes existing ones.
 *
 * Grants CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT unconditionally — the pair
 * a service account needs so a draft-only write (batch/composition default
 * `publish: "none"`) can be read back by the same account that wrote it.
 * Without VIEW_DRAFT_CONTENT, silverstripe/versioned's own canView() hook
 * denies the read the moment draft and live diverge, regardless of any
 * app-level canView() grant — see
 * docs/en/04_security-model.md#service-account-permissions (#42).
 * CONTENT_API_POPULATE is added only with populate=1 — a separate, narrower
 * grant most service accounts don't need.
 *
 * This task provisions permission *codes* only. A service account also
 * needs an app-level canView()/canEdit() grant extension on the classes it
 * writes — that's application code this task can't inject.
 *
 * Usage: `sake tasks:SetupContentApiServiceAccount group="Content API Service Accounts"`
 * (add `populate=1` too if the account needs batch/compositions/asset writes/page actions).
 */
class SetupServiceAccountTask extends BuildTask
{
    private static string $segment = 'SetupContentApiServiceAccount';

    protected $title = 'Set up a content API service-account group';

    protected $description = 'Provisions (or updates) a permission Group with the grants a '
        . 'content API service account needs: CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT always, '
        . 'CONTENT_API_POPULATE with populate=1. Idempotent.';

    public function run($request)
    {
        $rawGroup = $request->getVar('group');
        $title = $rawGroup === null ? 'Content API Service Accounts' : trim((string) $rawGroup);

        if ($title === '') {
            echo "group cannot be empty.\n";

            return;
        }

        $matches = Group::get()->filter('Title', $title);

        if ($matches->count() > 1) {
            echo sprintf(
                "Multiple groups titled \"%s\" found (IDs: %s) — refusing to guess which one to "
                    . "grant content API permissions to. Disambiguate by renaming or deleting the "
                    . "unintended group, then re-run.\n",
                $title,
                implode(', ', $matches->column('ID'))
            );

            return;
        }

        $group = $matches->first();

        if (!$group) {
            $group = Group::create();
            $group->Title = $title;
            $group->write();
            echo "Created group \"{$title}\" (#{$group->ID}).\n";
        } else {
            echo "Using existing group \"{$title}\" (#{$group->ID}).\n";
        }

        $codes = [ContentApiPermissions::ACCESS, 'VIEW_DRAFT_CONTENT'];

        if ($request->getVar('populate')) {
            $codes[] = ContentApiPermissions::POPULATE;
        }

        foreach ($codes as $code) {
            Permission::grant((int) $group->ID, $code);
            echo "  granted {$code}\n";
        }

        echo "\n";
        echo "This task provisions permission codes only. A service account also needs an "
            . "app-level canView()/canEdit() grant extension on the classes it writes — that's "
            . "application code this task can't inject. Assign a Member to this group, then mint "
            . "a token: sake tasks:MintContentApiToken email=<member-email>\n";
    }
}
