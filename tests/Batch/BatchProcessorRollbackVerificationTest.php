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

    /**
     * #127: an 'updated' result whose declared field(s) genuinely reverted
     * to the pre-image passes.
     */
    public function testAnUpdateWhoseFieldsGenuinelyRevertedPassesVerification(): void
    {
        $record = ApiTestObject::create(['Title' => 'Original title']);
        $record->write();

        $operations = [
            ['op' => 'update', 'class' => 'ApiTest', 'fields' => ['Title' => 'Would-be new title']],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => (int) $record->ID],
        ];
        $preImages = [ApiTestObject::class . ':' . $record->ID => ['Title' => 'Original title']];

        $this->assertTrue($this->verifyRollback($operations, $results, $preImages));
    }

    /**
     * The exact failure mode #127 exists to catch: the response claims the
     * update rolled back, but the new value is still on the row.
     */
    public function testAnUpdateWhoseNewValueSurvivedFailsVerification(): void
    {
        $record = ApiTestObject::create(['Title' => 'Would-be new title']);
        $record->write();

        $operations = [
            ['op' => 'update', 'class' => 'ApiTest', 'fields' => ['Title' => 'Would-be new title']],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => (int) $record->ID],
        ];
        $preImages = [ApiTestObject::class . ':' . $record->ID => ['Title' => 'Original title']];

        $this->assertFalse(
            $this->verifyRollback($operations, $results, $preImages),
            'a claimed rollback whose new value is still on the row must never be reported as verified'
        );
    }

    public function testAnUpdatedRecordThatHasVanishedFailsVerification(): void
    {
        $operations = [
            ['op' => 'update', 'class' => 'ApiTest', 'fields' => ['Title' => 'New title']],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => 999999999],
        ];
        $preImages = [ApiTestObject::class . ':999999999' => ['Title' => 'Original title']];

        $this->assertFalse(
            $this->verifyRollback($operations, $results, $preImages),
            'a claimed rollback cannot leave the updated record itself missing'
        );
    }

    /**
     * No pre-image was captured for this result's index at all — e.g. the
     * update's payload declared no `fields` (a relations-only change).
     * There is nothing to diff, so this fails closed rather than silently
     * reporting "verified" for a check that never ran.
     */
    public function testAnUpdateWithNoCapturedPreImageFailsClosed(): void
    {
        $operations = [
            ['op' => 'update', 'class' => 'ApiTest', 'relations' => ['Tags' => ['set' => []]]],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => 1],
        ];

        $this->assertFalse(
            $this->verifyRollback($operations, $results, []),
            'an update result with no captured pre-image cannot be verified'
        );
    }

    /**
     * Multiple declared fields must ALL match the pre-image — one reverted
     * field can't mask another that's still carrying the new value.
     */
    public function testAnUpdateWithOneOfSeveralFieldsStillWrongFailsVerification(): void
    {
        // Title genuinely reverted; Rank did not (still carries the
        // would-be new value) — one clean field must not mask the other.
        $record = ApiTestObject::create(['Title' => 'Original title', 'Rank' => 99]);
        $record->write();

        $operations = [
            [
                'op' => 'update',
                'class' => 'ApiTest',
                'fields' => ['Title' => 'Would-be new title', 'Rank' => 99],
            ],
        ];
        $results = [
            ['index' => 0, 'status' => 'updated', 'id' => (int) $record->ID],
        ];
        $preImages = [
            ApiTestObject::class . ':' . $record->ID => ['Title' => 'Original title', 'Rank' => 5],
        ];

        $this->assertFalse($this->verifyRollback($operations, $results, $preImages));
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
     * The default-to-archive fallback (no `mode` key on the operation at
     * all) must apply to a VERSIONED class too, not just the unversioned
     * case covered below — a missing key resolves through the same
     * `?? 'archive'` expression either way, but only a versioned class
     * exercises the "does the default actually verify against DRAFT, or
     * get mistaken for an unpublish-and-skip" question.
     */
    public function testAVersionedDeleteWithNoModeKeyDefaultsToArchiveAndIsVerified(): void
    {
        $operations = [
            ['op' => 'delete', 'class' => 'ApiTestVersioned'],
        ];
        $results = [
            ['index' => 0, 'status' => 'deleted', 'id' => 999999999],
        ];

        $this->assertFalse(
            $this->verifyRollback($operations, $results),
            'an unspecified mode on a versioned class must default to archive, not be treated as unpublish-and-skipped'
        );
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

    private function verifyRollback(array $operations, array $results, array $preImages = []): bool
    {
        $processor = BatchProcessor::create();
        $method = new ReflectionMethod(BatchProcessor::class, 'verifyRollback');
        $method->setAccessible(true);

        return $method->invoke($processor, $operations, $results, $preImages);
    }
}
