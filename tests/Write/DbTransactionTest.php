<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Write\DbTransaction;

/**
 * #136: `Database::withTransaction()` (silverstripe/framework) only ever
 * catches `Exception`, not `Throwable` — a `TypeError`/`ArgumentCountError`
 * escaping the work closure would leave `transactionRollback()` uncalled
 * and the transaction open. This targets `DbTransaction::run()` directly,
 * the one place every write path in this module goes through, rather than
 * exercising it indirectly via a full batch/composition request.
 */
class DbTransactionTest extends ContentApiTestCase
{
    public function testAnErrorEscapingTheWorkClosureRollsBackAWrittenRow(): void
    {
        $written = null;

        try {
            DbTransaction::run(function () use (&$written) {
                $record = ApiTestObject::create();
                $record->Title = 'Should be rolled back';
                $record->write();
                $written = $record->ID;

                // A real occurrence of this shape is documented in this
                // project's own CLAUDE.md as an ArgumentCountError inside
                // DBForeignKey::__construct — a bare TypeError from
                // application code proves the same failure class without
                // depending on that specific framework internal.
                throw new \TypeError('Simulated Error, not Exception, mid-write.');
            });
            $this->fail('Expected the TypeError to propagate out of DbTransaction::run().');
        } catch (\TypeError $error) {
            $this->assertSame('Simulated Error, not Exception, mid-write.', $error->getMessage());
        }

        $this->assertNotNull($written, 'The write must have happened before the Error, or this test proves nothing.');

        // The real assertion: query the DB directly rather than the ORM's
        // identity map, so a stale in-memory object can't mask a
        // transaction that was never actually rolled back.
        $this->assertNull(
            ApiTestObject::get()->byID($written),
            'The row written inside the transaction must not survive an Error escaping the work closure.'
        );
    }

    public function testANormalExceptionStillRollsBackTheSameAsBefore(): void
    {
        // Regression guard for the fix itself: catching \Error must not
        // interfere with (or double-rollback) the plain Exception path
        // Database::withTransaction() already handles on its own.
        $written = null;

        try {
            DbTransaction::run(function () use (&$written) {
                $record = ApiTestObject::create();
                $record->Title = 'Should also be rolled back';
                $record->write();
                $written = $record->ID;

                throw new \RuntimeException('Ordinary exception mid-write.');
            });
            $this->fail('Expected the RuntimeException to propagate out of DbTransaction::run().');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNotNull($written);
        $this->assertNull(ApiTestObject::get()->byID($written));
    }

    public function testASuccessfulWorkClosureCommitsNormally(): void
    {
        $id = null;

        DbTransaction::run(function () use (&$id) {
            $record = ApiTestObject::create();
            $record->Title = 'Committed';
            $record->write();
            $id = $record->ID;
        });

        $this->assertNotNull(ApiTestObject::get()->byID($id));
    }
}
