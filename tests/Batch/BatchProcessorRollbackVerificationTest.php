<?php

namespace Dynamic\ContentApi\Tests\Batch;

use Dynamic\ContentApi\Batch\BatchProcessor;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
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

    public function testUpdateResultsAreSkipped(): void
    {
        $operations = [
            ['op' => 'update', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => 1],
        ];

        $this->assertTrue(
            $this->verifyRollback($operations, $results),
            'an updated op has no pre-image to compare against, so it is never checked'
        );
    }

    public function testAnArchiveModeDeleteThatIsStillGoneFailsVerification(): void
    {
        $operations = [
            ['op' => 'delete', 'class' => 'ApiTestVersioned', 'mode' => 'archive'],
        ];
        $results = [
            ['index' => 0, 'status' => 'deleted', 'id' => 999999999],
        ];

        $this->assertFalse(
            $this->verifyRollback($operations, $results),
            'a genuine rollback restores the draft row — absence means the delete committed for real'
        );
    }

    public function testAnArchiveModeDeleteWhoseRecordIsBackPassesVerification(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Restored by a genuine rollback']);
        $record->write();

        $operations = [
            ['op' => 'delete', 'class' => 'ApiTestVersioned', 'mode' => 'archive'],
        ];
        $results = [
            ['index' => 0, 'status' => 'deleted', 'id' => (int) $record->ID],
        ];

        $this->assertTrue($this->verifyRollback($operations, $results));
    }

    /**
     * 'unpublish' mode on a versioned class only removes the LIVE row —
     * DRAFT is untouched either way, so this must pass regardless of
     * whether the draft record actually exists. Both sub-cases below (id
     * definitely absent, id definitely present) must return true, so the
     * test can't accidentally pass because the record happened to be
     * there rather than because the check was genuinely skipped.
     */
    public function testAnUnpublishModeDeleteIsSkippedRegardlessOfDraftState(): void
    {
        $operations = [
            ['op' => 'delete', 'class' => 'ApiTestVersioned', 'mode' => 'unpublish'],
        ];

        $this->assertTrue(
            $this->verifyRollback($operations, [
                ['index' => 0, 'status' => 'deleted', 'id' => 999999999],
            ]),
            'absent from draft: still skipped, not (coincidentally) verified-and-passing'
        );

        $record = ApiTestVersionedObject::create(['Title' => 'Present in draft regardless']);
        $record->write();

        $this->assertTrue(
            $this->verifyRollback($operations, [
                ['index' => 0, 'status' => 'deleted', 'id' => (int) $record->ID],
            ]),
            'present in draft: still skipped, not incidentally verified'
        );
    }

    /**
     * Every delete mode on an unversioned class converges on a real
     * delete() (PublishOrchestrator::delete()) — mode never means "skip"
     * here, unlike the versioned case above.
     */
    public function testAnUnversionedDeleteIsVerifiedRegardlessOfMode(): void
    {
        foreach (['unpublish', 'hard', 'archive'] as $mode) {
            $operations = [
                ['op' => 'delete', 'class' => 'ApiTest', 'mode' => $mode],
            ];
            $results = [
                ['index' => 0, 'status' => 'deleted', 'id' => 999999999],
            ];

            $this->assertFalse(
                $this->verifyRollback($operations, $results),
                sprintf('mode "%s" on an unversioned class must still be verified', $mode)
            );
        }
    }

    public function testAnUnversionedDeleteDefaultsToArchiveModeWhenUnspecified(): void
    {
        $operations = [
            ['op' => 'delete', 'class' => 'ApiTest'],
        ];
        $results = [
            ['index' => 0, 'status' => 'deleted', 'id' => 999999999],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results));
    }

    public function testADeleteWithAMissingOperationFailsClosed(): void
    {
        $operations = [
            ['op' => 'delete', 'class' => 'ApiTestVersioned', 'mode' => 'archive'],
        ];
        $results = [
            // index 1 has no corresponding operation.
            ['index' => 1, 'status' => 'deleted', 'id' => 999999999],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results));
    }

    public function testAnUnresolvableClassOnADeleteFailsClosed(): void
    {
        $operations = [
            ['op' => 'delete', 'class' => 'NoSuchRegisteredClassRef', 'mode' => 'archive'],
        ];
        $results = [
            ['index' => 0, 'status' => 'deleted', 'id' => 1],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results));
    }

    /**
     * A mixed batch shouldn't let one branch's early `continue` leak into
     * the other's handling — a surviving created row after a genuinely
     * verified delete must still be caught.
     */
    public function testACreatedAndADeletedResultAreBothCheckedInTheSameBatch(): void
    {
        $survivingCreate = ApiTestObject::create(['Title' => 'Still here']);
        $survivingCreate->write();

        $operations = [
            ['op' => 'create', 'class' => 'ApiTest'],
            ['op' => 'delete', 'class' => 'ApiTestVersioned', 'mode' => 'archive'],
        ];
        $results = [
            ['index' => 0, 'status' => 'created', 'id' => (int) $survivingCreate->ID],
            ['index' => 1, 'status' => 'deleted', 'id' => 999999999],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results));
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
