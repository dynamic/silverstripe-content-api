<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Write\DbTransaction;
use SilverStripe\ORM\DB;

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

    /**
     * #136 review follow-up: the three tests above all exercise a single,
     * top-level DbTransaction::run() call. The real production shape is
     * nested — BatchProcessor::process() opens one transaction, and every
     * op inside it reaches RecordWriter::write(), which opens another via
     * DbTransaction::run() again. That's the only place
     * NestedTransactionManager's savepoint bookkeeping actually engages,
     * and it's exactly what the issue's own writeup worried about
     * ("transaction is left open at its current nesting level").
     *
     * Asserts on transactionDepth() before/after, not just the row —
     * nothing else in this suite would catch a nesting-counter regression
     * (e.g. an unbalanced rollback that unwinds one level too many/few).
     */
    public function testANestedErrorRollsBackBothLevelsAndLeavesNestingBalanced(): void
    {
        $depthBefore = DB::get_conn()->transactionDepth();
        $outerWritten = null;
        $innerWritten = null;

        try {
            DbTransaction::run(function () use (&$outerWritten, &$innerWritten) {
                $outer = ApiTestObject::create();
                $outer->Title = 'Outer — should be rolled back too';
                $outer->write();
                $outerWritten = $outer->ID;

                DbTransaction::run(function () use (&$innerWritten) {
                    $inner = ApiTestObject::create();
                    $inner->Title = 'Inner — should be rolled back';
                    $inner->write();
                    $innerWritten = $inner->ID;

                    throw new \TypeError('Simulated Error inside a nested transaction.');
                });
            });
            $this->fail('Expected the TypeError to propagate out of the outer DbTransaction::run().');
        } catch (\TypeError) {
            // Expected.
        }

        $this->assertNotNull($outerWritten);
        $this->assertNotNull($innerWritten);

        $this->assertNull(
            ApiTestObject::get()->byID($innerWritten),
            'The inner write must not survive an Error inside the nested transaction.'
        );
        $this->assertNull(
            ApiTestObject::get()->byID($outerWritten),
            'The outer write must also be rolled back — a nested Error must not leave the outer '
                . 'transaction thinking it can still commit its own, now-inconsistent, work.'
        );
        $this->assertSame(
            $depthBefore,
            DB::get_conn()->transactionDepth(),
            'Nesting must return to its pre-call depth — an Error escaping a nested transaction '
                . 'must not leave the connection thinking it is still inside one.'
        );
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
