<?php

namespace Dynamic\ContentApi\Security;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Registry\ClassRegistry;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Two-stage ACL: class-level access (config + module permission codes) and
 * record-level access (the model's own can*() methods).
 *
 * Class-level checks never call can*() on singletons — a lesson from
 * project-feedback, where tenant-scoped can*() methods 403 on unhydrated
 * records. Record checks always run on real, loaded records.
 */
class PermissionPolicy
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ClassRegistry $registry = null;

    /**
     * Gate a verb on a class: member must hold CONTENT_API_ACCESS and the
     * class must expose the verb via api_access config.
     *
     * @throws ApiError FORBIDDEN | FORBIDDEN_CLASS
     */
    public function checkClassAccess(string $className, string $verb, Member $member): void
    {
        if (!Permission::checkMember($member, ContentApiPermissions::ACCESS)) {
            throw new ApiError(
                ErrorCode::FORBIDDEN,
                'Member does not have content API access.'
            );
        }

        if (!in_array($verb, $this->registry->accessVerbs($className), true)) {
            throw new ApiError(
                ErrorCode::FORBIDDEN_CLASS,
                sprintf('Class "%s" does not allow "%s" via the content API.', $className, $verb)
            );
        }
    }

    /**
     * Gate a verb on a loaded record via the model's own permission methods.
     *
     * @throws ApiError FORBIDDEN_RECORD
     */
    public function checkRecordAccess(DataObject $record, string $verb, Member $member): void
    {
        $allowed = match ($verb) {
            'read' => $record->canView($member),
            'update', 'action' => $record->canEdit($member),
            'delete' => $record->canDelete($member),
            default => false,
        };

        if (!$allowed) {
            throw new ApiError(
                ErrorCode::FORBIDDEN_RECORD,
                sprintf(
                    'Not allowed to %s %s #%d.',
                    $verb,
                    $record->ClassName,
                    $record->ID
                )
            );
        }
    }

    /**
     * Whether a member may see a record at all (used for filtering lists
     * without throwing).
     */
    public function canViewRecord(DataObject $record, Member $member): bool
    {
        return (bool) $record->canView($member);
    }

    /**
     * Gate population-domain endpoints (batch, compositions, assets, page
     * actions).
     *
     * @throws ApiError FORBIDDEN
     */
    public function checkPopulateAccess(Member $member): void
    {
        if (!Permission::checkMember($member, ContentApiPermissions::POPULATE)) {
            throw new ApiError(
                ErrorCode::FORBIDDEN,
                'Member does not have content population access.'
            );
        }
    }
}
