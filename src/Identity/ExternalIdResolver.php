<?php

namespace Dynamic\ContentApi\Identity;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * Resolves records by their external identifier (the API's idempotency key).
 *
 * Matching is strict: zero matches is a miss, exactly one is a hit, more than
 * one is a MULTIPLE_MATCHES conflict — the API never guesses.
 */
class ExternalIdResolver
{
    use Configurable;
    use Injectable;

    /**
     * DB column holding the external identifier. Defaults to the fixtures
     * recipe's column so previously-populated sites are addressable as-is.
     */
    private static string $external_id_field = 'FixtureIdentifier';

    public function fieldName(): string
    {
        return (string) static::config()->get('external_id_field');
    }

    /**
     * Whether a class carries the external identifier column.
     */
    public function supports(string $className): bool
    {
        $spec = DataObject::getSchema()->fieldSpec($className, $this->fieldName());

        return $spec !== null;
    }

    /**
     * @throws ApiError EXTERNAL_ID_UNSUPPORTED when the class lacks the column
     * @throws ApiError MULTIPLE_MATCHES when the identifier is ambiguous
     */
    public function tryFind(string $className, string $externalId): ?DataObject
    {
        $this->assertSupported($className);

        return $this->resolveSingleMatch(
            DataObject::get($className)->filter($this->fieldName(), $externalId),
            sprintf('%s record', $className),
            $externalId
        );
    }

    /**
     * Same matching/MULTIPLE_MATCHES semantics as tryFind(), scoped to an
     * already-fetched list (e.g. an owning record's has_many relation)
     * instead of a fresh DataObject::get() query — for callers that must not
     * adopt a record living outside that list's scope (module #19).
     *
     * @throws ApiError MULTIPLE_MATCHES
     */
    public function tryFindScoped(DataList $list, string $externalId, string $errorLabel = 'record'): ?DataObject
    {
        return $this->resolveSingleMatch($list->filter($this->fieldName(), $externalId), $errorLabel, $externalId);
    }

    /**
     * @throws ApiError MULTIPLE_MATCHES
     */
    private function resolveSingleMatch(DataList $matches, string $errorLabel, string $externalId): ?DataObject
    {
        $records = $matches->limit(2)->toArray();

        if (count($records) > 1) {
            throw new ApiError(
                ErrorCode::MULTIPLE_MATCHES,
                sprintf('External id "%s" matches more than one %s.', $externalId, $errorLabel)
            );
        }

        return $records[0] ?? null;
    }

    /**
     * @throws ApiError NOT_FOUND | MULTIPLE_MATCHES | EXTERNAL_ID_UNSUPPORTED
     */
    public function find(string $className, string $externalId): DataObject
    {
        $record = $this->tryFind($className, $externalId);

        if (!$record) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('No %s found with external id "%s".', $className, $externalId)
            );
        }

        return $record;
    }

    /**
     * Refuse to give `$record` an external id that can't identify it (#240):
     *
     * - a key no `ext:` path could address (`/`, `?`, `#`, control
     *   characters), so a client can't create a record it can't read back;
     * - a key another record already holds anywhere in the class tree that
     *   carries the column (the highest such ancestor, not just
     *   `$record`'s own class), so a subclass can't take a parent-class
     *   row's key and an element can't be re-created under a new class
     *   beside the one already holding its id. Every later `ext:` lookup
     *   would otherwise answer 409 MULTIPLE_MATCHES for both.
     *
     * Re-sending the key a record already has is always fine (including a
     * key composition children legitimately share across elements), and an
     * empty key clears it.
     *
     * @throws ApiError PAYLOAD_INVALID | ALREADY_EXISTS
     */
    public function assertAssignable(DataObject $record, string $externalId): void
    {
        if ($externalId === '' || (string) $record->getField($this->fieldName()) === $externalId) {
            return;
        }

        if (preg_match('#[/?\#\x00-\x1F\x7F]#', $externalId)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'External id "%s" must contain no "/", "?", "#" or control characters, '
                        . 'or it could not be addressed as ext:<id>.',
                    $externalId
                )
            );
        }

        $scope = get_class($record);

        while (($parent = get_parent_class($scope)) && $parent !== DataObject::class && $this->supports($parent)) {
            $scope = $parent;
        }

        $holders = DataObject::get($scope)->filter($this->fieldName(), $externalId);

        if ($record->isInDB()) {
            $holders = $holders->exclude('ID', (int) $record->ID);
        }

        $holder = $holders->first();

        if ($holder) {
            throw new ApiError(
                ErrorCode::ALREADY_EXISTS,
                sprintf(
                    'External id "%s" is already held by %s #%d. An external id identifies one record; '
                        . 'to change a record\'s class keep it on that record (convert it), otherwise use a new id.',
                    $externalId,
                    $holder->ClassName,
                    $holder->ID
                )
            );
        }
    }

    /**
     * @throws ApiError EXTERNAL_ID_UNSUPPORTED
     */
    public function assertSupported(string $className): void
    {
        if (!$this->supports($className)) {
            throw new ApiError(
                ErrorCode::EXTERNAL_ID_UNSUPPORTED,
                sprintf(
                    'Class "%s" has no "%s" column — apply %s to use external ids.',
                    $className,
                    $this->fieldName(),
                    ExternalIdentifierExtension::class
                )
            );
        }
    }
}
