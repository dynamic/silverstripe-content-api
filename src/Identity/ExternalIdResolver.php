<?php

namespace Dynamic\ContentApi\Identity;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
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

        $matches = DataObject::get($className)
            ->filter($this->fieldName(), $externalId)
            ->limit(2);

        $records = $matches->toArray();

        if (count($records) > 1) {
            throw new ApiError(
                ErrorCode::MULTIPLE_MATCHES,
                sprintf(
                    'External id "%s" matches more than one %s record.',
                    $externalId,
                    $className
                )
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
