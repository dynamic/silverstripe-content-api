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
 *
 * Deliberately does NOT call colymba's RESTfulAPI::api_access_control()
 * static: its model-permission path resolves the member via
 * RESTfulAPI::$instance, which is only set when the request went through
 * colymba's own controller — from this surface it would be null.
 */
class PermissionPolicy
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ClassRegistry $registry = null;

    /**
     * Gate on the CONTENT_API_ACCESS permission alone, independent of any
     * class. Useful where the target class is only known after a lookup (asset
     * reads), so an unauthorised member is refused before the record is
     * fetched rather than leaking its existence.
     *
     * @throws ApiError FORBIDDEN
     */
    public function checkAccess(Member $member): void
    {
        if (!Permission::checkMember($member, ContentApiPermissions::ACCESS)) {
            throw new ApiError(
                ErrorCode::FORBIDDEN,
                'Member does not have content API access.'
            );
        }
    }

    /**
     * Gate a verb on a class: member must hold CONTENT_API_ACCESS and the
     * class must expose the verb via api_access config.
     *
     * @throws ApiError FORBIDDEN | FORBIDDEN_CLASS
     */
    public function checkClassAccess(string $className, string $verb, Member $member): void
    {
        $this->checkAccess($member);

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
     * Gate record creation via the model's canCreate(), passing a context
     * built from the payload's has_one keys — tenant-scoped canCreate()
     * implementations need the hydrated parent records (the
     * project-feedback ApiPermissionManager lesson, generalized).
     *
     * @param array<string, mixed> $fields the payload's fields block
     * @throws ApiError FORBIDDEN_RECORD
     */
    public function checkCreateAccess(string $className, Member $member, array $fields = []): void
    {
        $context = $this->buildCreateContext($className, $fields);

        if (!DataObject::singleton($className)->canCreate($member, $context)) {
            throw new ApiError(
                ErrorCode::FORBIDDEN_RECORD,
                sprintf('Not allowed to create %s.', $className)
            );
        }
    }

    /**
     * Hydrate has_one relations named in the payload (either `Relation` or
     * `RelationID` form) into a canCreate() context array.
     *
     * A polymorphic has_one (declared `'Relation' => DataObject::class`)
     * can't be hydrated via `DataObject::get_by_id($relationClass, $id)` —
     * `$relationClass` is the literal abstract `DataObject::class`, and core
     * refuses to query it directly ("cannot query non-subclass DataObject
     * directly"). The concrete target class only exists if the payload named
     * one via a "class" hint (the same convention WriteApplicator's
     * resolveRelation() requires for the write itself). An absent value is
     * skipped (the relation simply isn't in the payload), but a present,
     * malformed, or unresolvable hint is rejected here with the same
     * PAYLOAD_INVALID error resolveRelation() would raise for the identical
     * shape during the write itself, rather than silently omitting the key
     * from a tenant-scoped canCreate()'s context — a fail-open gap a project
     * feedback lesson (see class doc) specifically warned against.
     *
     * @return array<string, mixed>
     */
    protected function buildCreateContext(string $className, array $fields): array
    {
        $context = ['Payload' => $fields];
        $hasOne = (array) DataObject::singleton($className)->hasOne();

        foreach ($hasOne as $relationName => $relationClass) {
            $value = $fields[$relationName] ?? $fields[$relationName . 'ID'] ?? null;
            $isPolymorphic = $relationClass === DataObject::class;

            if ($isPolymorphic) {
                if ($value === null) {
                    continue;
                }

                $relationClass = $this->registry->resolvePolymorphicHint($relationName, $value);
            }

            $id = match (true) {
                is_int($value), is_string($value) && ctype_digit((string) $value) => (int) $value,
                is_array($value) && isset($value['id']) => (int) $value['id'],
                default => 0,
            };

            if ($id > 0) {
                $context[$relationName] = DataObject::get_by_id($relationClass, $id);
            }
        }

        return $context;
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
