<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Logging\RequestLogger;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;

/**
 * End-to-end coverage for #207 through the real controller
 * ({@see \Dynamic\ContentApi\Control\ContentApiController::withEnvelope()}),
 * not just {@see RequestLogger} in isolation — proves the hook is actually
 * wired in, and that a `POST batch` partial failure is logged correctly
 * despite the wire response being a plain 200.
 */
class RequestLoggingTest extends ContentApiTestCase
{
    private string $adminToken;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');

        $this->logHandler = new TestHandler();
        Injector::inst()->registerService(
            new Logger('test', [$this->logHandler]),
            LoggerInterface::class
        );
    }

    public function testDisabledByDefaultProducesNoLogEntryForARealRequest(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', []);

        $response = $this->apiGet('records/ApiTest', $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEmpty(
            array_filter(
                $this->logHandler->getRecords(),
                static fn (LogRecord $r): bool => $r['message'] === 'Content API request'
            ),
            'off by default: a real request must produce no request-log entry'
        );
    }

    public function testEnabledLogsTheRealEndpointAndStatus(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        $response = $this->apiGet('records/ApiTest', $this->adminToken);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $record = $this->findRequestLogRecord();
        $this->assertNotNull($record, 'expected exactly one Content API request log entry');
        $this->assertSame('handleReadList', $record['context']['endpoint']);
        $this->assertSame('GET', $record['context']['method']);
        $this->assertSame(200, $record['context']['status']);
        $this->assertNull($record['context']['errorCode']);
    }

    public function testA4xxResponseLogsTheMatchingErrorCode(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        $response = $this->apiGet('records/ApiTest/999999', $this->adminToken);
        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());

        $record = $this->findRequestLogRecord();
        $this->assertNotNull($record);
        $this->assertSame(404, $record['context']['status']);
        $this->assertSame('NOT_FOUND', $record['context']['errorCode']);
    }

    /**
     * #207's whole reason for existing: a `POST batch` with `atomic` unset
     * (default false) returns HTTP 200 even when individual operations
     * failed — see `BatchTest::testErrorIsolationWithoutAtomic()`. A log
     * keyed only on HTTP status would record this as a clean success; the
     * `opFailures` field is what makes it visible.
     */
    public function testPartiallyFailedBatchLogsOpFailuresDespiteHttp200(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        $response = $this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'log-iso-1', 'fields' => ['Title' => 'First']],
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'log-iso-2', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $record = $this->findRequestLogRecord();
        $this->assertNotNull($record);
        $this->assertSame(200, $record['context']['status'], 'the wire response itself is a plain 200');
        $this->assertSame(1, $record['context']['opFailures'], 'the per-op failure must still be visible in the log');
    }

    private function findRequestLogRecord(): ?LogRecord
    {
        $matches = array_values(array_filter(
            $this->logHandler->getRecords(),
            static fn (LogRecord $r): bool => $r['message'] === 'Content API request'
        ));

        return $matches[0] ?? null;
    }
}
