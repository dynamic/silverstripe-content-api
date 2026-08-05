<?php

namespace Dynamic\ContentApi\Tests\Batch;

use Dynamic\ContentApi\Batch\BatchProcessor;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use ReflectionMethod;

/**
 * Direct coverage for #70's `BatchProcessor::verifyRollback()` — the
 * framework's own transaction-rollback mechanism works correctly under
 * normal conditions (confirmed: `BatchTest::testAtomicBatchRollsBack()`
 * passes with this method wired in), so a full integration test can't
 * force a genuine "the rollback silently didn't happen" scenario to prove
 * this method reports it honestly. Tested directly instead: feed it a
 * results array claiming a row was created that's still actually in the
 * database (simulating exactly what a corrupted rollback would look like)
 * and confirm it says so, rather than trusting the claim.
 */
class BatchProcessorRollbackVerificationTest extends ContentApiTestCase
{
    public function testReturnsTrueWhenAllClaimedCreatesAreGenuinelyGone(): void
    {
        $operations = [
            ['op' => 'create', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'created', 'id' => 999999999],
        ];

        $this->assertTrue($this->verifyRollback($operations, $results));
    }

    public function testReturnsFalseWhenAClaimedCreateIsStillInTheDatabase(): void
    {
        $record = ApiTestObject::create(['Title' => 'Still here despite the claimed rollback']);
        $record->write();

        $operations = [
            ['op' => 'create', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'created', 'id' => (int) $record->ID],
        ];

        $this->assertFalse(
            $this->verifyRollback($operations, $results),
            'a row that genuinely still exists must never be reported as rolled back'
        );
    }

    public function testNonCreateResultsAreSkipped(): void
    {
        $operations = [
            ['op' => 'update', 'class' => 'ApiTest'],
            ['op' => 'delete', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => 1],
            ['index' => 1, 'status' => 'deleted', 'id' => 2],
        ];

        $this->assertTrue(
            $this->verifyRollback($operations, $results),
            'update/delete results have no pre-image to compare against — only created rows are checked'
        );
    }

    public function testErrorResultsAreSkipped(): void
    {
        $operations = [
            ['op' => 'create', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'error', 'error' => ['code' => 'VALIDATION_FAILED']],
        ];

        $this->assertTrue($this->verifyRollback($operations, $results));
    }

    /**
     * The class resolved fine when the op originally ran (that's how it
     * got marked 'created' in the first place) — if it fails to resolve
     * now, fail toward "can't verify" rather than risk a false "confirmed
     * rolled back".
     */
    public function testUnresolvableClassFailsClosed(): void
    {
        $operations = [
            ['op' => 'create', 'class' => 'NoSuchRegisteredClassRef'],
        ];
        $results = [
            ['index' => 0, 'status' => 'created', 'id' => 1],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results));
    }

    /**
     * The loop must not short-circuit correctly only by accident (e.g. an
     * off-by-one always inspecting just the first entry) — a second,
     * still-present record after a genuinely-gone first one must still be
     * caught.
     */
    public function testASecondSurvivingRecordIsCaughtEvenAfterAGenuinelyGoneFirstOne(): void
    {
        $record = ApiTestObject::create(['Title' => 'Second one still here']);
        $record->write();

        $operations = [
            ['op' => 'create', 'class' => 'ApiTest'],
            ['op' => 'create', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'created', 'id' => 999999999],
            ['index' => 1, 'status' => 'created', 'id' => (int) $record->ID],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results));
    }

    private function verifyRollback(array $operations, array $results): bool
    {
        $processor = BatchProcessor::create();
        $method = new ReflectionMethod(BatchProcessor::class, 'verifyRollback');
        $method->setAccessible(true);

        return $method->invoke($processor, $operations, $results);
    }
}
