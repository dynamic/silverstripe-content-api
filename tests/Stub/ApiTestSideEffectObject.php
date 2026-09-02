<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * #203 regression stub — mirrors `ElementOembed::onBeforeWrite()`'s real
 * shape (confirmed live, Rockline Industrial): a write to an UNRELATED
 * table (`ApiTestSideEffectLog`, standing in for `EmbedObject`) as a side
 * effect of this record's own `write()`, triggered inside
 * `RecordWriter::write()`'s `DbTransaction::run()` block just like any
 * other `onBeforeWrite()` hook. Exists to answer empirically whether that
 * side-effect write actually survives a batch atomic-failure rollback or a
 * `dryRun: true` probe — this module has no `Oembed`/`EmbedObject`
 * dependency of its own to test against directly.
 */
class ApiTestSideEffectObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestSideEffectObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $log = ApiTestSideEffectLog::create();
        $log->Note = 'onBeforeWrite side effect for "' . $this->Title . '"';
        $log->write();
    }
}
