<?php

namespace Dynamic\ContentApi\Tasks\Support;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

/**
 * Provision (or update) the permission Group a content API service account
 * needs. Idempotent — re-running only adds missing grants, never duplicates
 * or removes existing ones. Branch-neutral: branch `1`'s SS6
 * `SetupServiceAccountTask` adapter (`execute(InputInterface, PolyOutput): int`)
 * calls this directly rather than duplicating the business logic — see #65.
 * `ss5`'s `SetupServiceAccountTask` still carries the inline logic pending a
 * follow-up port to its own `run($request): void` adapter over this class.
 *
 * Grants CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT unconditionally — the pair
 * a service account needs so a draft-only write (batch/composition default
 * `publish: "none"`) can be read back by the same account that wrote it.
 * Without VIEW_DRAFT_CONTENT, silverstripe/versioned's own canView() hook
 * denies the read the moment draft and live diverge, regardless of any
 * app-level canView() grant — see
 * docs/en/04_security-model.md#service-account-permissions (#42).
 * CONTENT_API_POPULATE is added only when $populate is true — a separate,
 * narrower grant most service accounts don't need.
 *
 * Permission codes only: the record-level can*() grant a service account
 * separately needs (Security\ContentApiGrantExtension) is applied by a
 * project via YAML, per class — this task can't reach into a project's
 * config to add it, and doing so blindly would grant it to every class a
 * project happens to have, not just the ones meant to be writable.
 */
class ServiceAccountProvisioner
{
    use Injectable;

    public function provision(string $groupTitle, bool $populate = false): TaskResult
    {
        $title = trim($groupTitle);

        if ($title === '') {
            return new TaskResult(TaskStatus::Invalid, ['--group cannot be empty.']);
        }

        $matches = Group::get()->filter('Title', $title);

        if ($matches->count() > 1) {
            return new TaskResult(TaskStatus::Failure, [sprintf(
                'Multiple groups titled "%s" found (IDs: %s) — refusing to guess which one to '
                    . 'grant content API permissions to. Disambiguate by renaming or deleting the '
                    . 'unintended group, then re-run.',
                $title,
                implode(', ', $matches->column('ID'))
            )]);
        }

        $lines = [];
        $group = $matches->first();

        if (!$group) {
            $group = Group::create();
            $group->Title = $title;
            $group->write();
            $lines[] = "Created group \"{$title}\" (#{$group->ID}).";
        } else {
            $lines[] = "Using existing group \"{$title}\" (#{$group->ID}).";
        }

        $codes = [ContentApiPermissions::ACCESS, 'VIEW_DRAFT_CONTENT'];

        if ($populate) {
            $codes[] = ContentApiPermissions::POPULATE;
        }

        foreach ($codes as $code) {
            Permission::grant((int) $group->ID, $code);
            $lines[] = "  granted {$code}";
        }

        $lines[] = '';
        $lines[] = 'This task provisions permission codes only. A service account also needs a '
            . 'canView()/canEdit()/canCreate()/canDelete() grant on the classes it writes — apply '
            . 'Dynamic\ContentApi\Security\ContentApiGrantExtension to those classes via YAML '
            . '(only classes declaring their own content_api_access are grantable; see '
            . 'docs/en/04_security-model.md). Assign a Member to this group, then mint a token.';

        return new TaskResult(TaskStatus::Success, $lines);
    }
}
