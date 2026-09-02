<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Write\DryRunContext;

/**
 * #203: direct coverage for the primitive itself, isolated from the batch
 * integration tests (`BatchTest::testDryRunRollsBackASideEffectWriteToAnUnrelatedTable()`
 * and friends) that exercise it indirectly via `BatchProcessor::runDryRun()`.
 */
class DryRunContextTest extends ContentApiTestCase
{
    public function testIsActiveIsFalseOutsideRun(): void
    {
        $this->assertFalse(DryRunContext::isActive());
    }

    public function testIsActiveIsTrueDuringRun(): void
    {
        $observed = null;

        DryRunContext::run(function () use (&$observed) {
            $observed = DryRunContext::isActive();
        });

        $this->assertTrue($observed);
    }

    public function testIsActiveIsRestoredAfterRunEvenWhenWorkThrows(): void
    {
        try {
            DryRunContext::run(function () {
                throw new \RuntimeException('mid-run failure');
            });
            $this->fail('Expected the exception to propagate out of run().');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertFalse(DryRunContext::isActive());
    }

    public function testNestedRunRestoresThePriorStateNotUnconditionallyFalse(): void
    {
        $innerObserved = null;
        $afterInnerObserved = null;

        DryRunContext::run(function () use (&$innerObserved, &$afterInnerObserved) {
            DryRunContext::run(function () use (&$innerObserved) {
                $innerObserved = DryRunContext::isActive();
            });

            $afterInnerObserved = DryRunContext::isActive();
        });

        $this->assertTrue($innerObserved);
        $this->assertTrue(
            $afterInnerObserved,
            'An inner run() completing must not turn off a still-active outer run()'
        );
        $this->assertFalse(DryRunContext::isActive());
    }
}
