<?php

namespace Dynamic\ContentApi\Tests\Logging;

use Dynamic\ContentApi\Logging\RequestLogger;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

class RequestLoggerTest extends ContentApiTestCase
{
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();

        // Off everywhere by default (matches the module default), so only
        // the config/env-var override under test can turn it on.
        Config::modify()->set(RequestLogger::class, 'enabled_environments', []);

        $this->logHandler = new TestHandler();
        Injector::inst()->registerService(
            new Logger('test', [$this->logHandler]),
            LoggerInterface::class
        );
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_CONTENT_API_REQUEST_LOG', false);

        parent::tearDown();
    }

    public function testDisabledByDefaultLogsNothing(): void
    {
        $this->log(new RequestLogger());

        $this->assertEmpty($this->logHandler->getRecords(), 'off by default must not log anything');
    }

    public function testEnabledViaConfigLogsOneInfoEntry(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        $this->log(new RequestLogger());

        $this->assertTrue($this->logHandler->hasInfoThatContains('Content API request'));
        $this->assertCount(1, $this->logHandler->getRecords());
    }

    /**
     * @dataProvider allowingValueProvider
     */
    public function testEnvVarOverridesEnabledEnvironmentsOn(mixed $value): void
    {
        Environment::setEnv('SS_CONTENT_API_REQUEST_LOG', $value);

        $this->log(new RequestLogger());

        $this->assertTrue($this->logHandler->hasInfoThatContains('Content API request'));
    }

    public static function allowingValueProvider(): array
    {
        return [
            '1 string' => ['1'],
            'true lowercase' => ['true'],
            'native bool true' => [true],
            'native int 1' => [1],
        ];
    }

    /**
     * A force-OFF direction doesn't exist by design — see the class
     * docblock. Falsy env values behave exactly like an unset var: they
     * defer to enabled_environments, they don't suppress it.
     */
    public function testFalsyEnvValueDefersToEnabledEnvironments(): void
    {
        Environment::setEnv('SS_CONTENT_API_REQUEST_LOG', 'false');
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        $this->log(new RequestLogger());

        $this->assertTrue(
            $this->logHandler->hasInfoThatContains('Content API request'),
            'a falsy env var must not override an enabled_environments entry off'
        );
    }

    public function testEntryCarriesTheExpectedFields(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        $request = new HTTPRequest('GET', 'content-api/v1/records/ApiTest/1');
        $response = new HTTPResponse('{}', 404);

        (new RequestLogger())->log($request, $response, null, [
            'endpoint' => 'handleReadOne',
            'method' => 'GET',
            'classRef' => 'ApiTest',
            'action' => null,
            'status' => 404,
            'errorCode' => 'NOT_FOUND',
            'durationMs' => 12.34,
            'responseBytes' => 2,
            'opFailures' => null,
        ]);

        $record = $this->logHandler->getRecords()[0];
        $this->assertSame('handleReadOne', $record['context']['endpoint']);
        $this->assertSame('GET', $record['context']['method']);
        $this->assertSame('ApiTest', $record['context']['classRef']);
        $this->assertSame(404, $record['context']['status']);
        $this->assertSame('NOT_FOUND', $record['context']['errorCode']);
        $this->assertNull($record['context']['memberId'], 'no authenticated member on this call');
    }

    /**
     * #207: this runs on every request, including every 200 — a broken
     * project logger service must never turn a successful response into a
     * 500. Same "logging is secondary" contract as EnvironmentGate's own
     * best-effort log call.
     */
    public function testAThrowingLoggerDoesNotPropagate(): void
    {
        Config::modify()->set(RequestLogger::class, 'enabled_environments', ['dev']);

        Injector::inst()->registerService(
            new class implements LoggerInterface {
                use \Psr\Log\LoggerTrait;

                public function log($level, $message, array $context = []): void
                {
                    throw new \RuntimeException('broken project logger');
                }
            },
            LoggerInterface::class
        );

        $this->log(new RequestLogger());

        $this->addToAssertionCount(1); // no exception escaping is the assertion
    }

    private function log(RequestLogger $logger): void
    {
        $request = new HTTPRequest('GET', 'content-api/v1/records/ApiTest');
        $response = new HTTPResponse('{}', 200);

        $logger->log($request, $response, null, [
            'endpoint' => 'handleReadList',
            'method' => 'GET',
            'classRef' => 'ApiTest',
            'action' => null,
            'status' => 200,
            'errorCode' => null,
            'durationMs' => 1.0,
            'responseBytes' => 2,
            'opFailures' => null,
        ]);
    }
}
