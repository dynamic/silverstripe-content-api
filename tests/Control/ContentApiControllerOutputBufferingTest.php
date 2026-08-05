<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Control\ContentApiController;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Direct coverage for `ContentApiController::withEnvelope()`'s output
 * buffering (#70). A full HTTP round-trip test (`BatchTest`'s deprecation
 * regression tests) can't actually exercise this: SilverStripe's
 * `FunctionalTest` invokes the controller in-process and inspects the
 * `HTTPResponse` object directly, bypassing the real PHP output stream a
 * live web request concatenates stray `echo` output onto (confirmed
 * empirically against a real HTTP request during development — see #70).
 * These tests invoke `withEnvelope()` directly instead, so the buffering
 * itself is what's under test, independent of what actually produced the
 * stray output in production (a deprecation notice, a warning, a stray
 * var_dump — any of them take the same path).
 */
class ContentApiControllerOutputBufferingTest extends SapphireTest
{
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logHandler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$this->logHandler]), LoggerInterface::class);
    }

    public function testStrayOutputDuringASuccessfulCallDoesNotReachTheResponseBody(): void
    {
        $response = $this->invokeWithEnvelope(function () {
            echo '<html>some stray debug output that must never reach the client</html>';

            return ['data' => ['ok' => true]];
        });

        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body, 'response body must be clean, parseable JSON');
        $this->assertSame(['ok' => true], $body['data']);
        $this->assertStringNotContainsString('stray debug output', $response->getBody());
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('stray output'),
            'the suppressed output must still be logged, not silently discarded'
        );
    }

    public function testStrayOutputDuringAFailedCallDoesNotReachTheResponseBody(): void
    {
        $response = $this->invokeWithEnvelope(function () {
            echo '<html>stray output alongside a real error</html>';

            throw new ApiError(ErrorCode::VALIDATION_FAILED, 'a genuine failure');
        });

        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body, 'response body must be clean, parseable JSON even on the error path');
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertStringNotContainsString('stray output alongside', $response->getBody());
        $this->assertTrue($this->logHandler->hasWarningThatContains('stray output'));
    }

    public function testEndpointClosingItsOwnBufferIsReportedAsAnImbalanceNotSilentlyIgnored(): void
    {
        $response = $this->invokeWithEnvelope(function () {
            // Simulates application code with its own unbalanced
            // ob_end_clean()/ob_end_flush() call that closes the buffer
            // withEnvelope() opened — whatever it contained is already
            // gone by the time withEnvelope() regains control.
            ob_end_clean();

            return ['data' => ['ok' => true]];
        });

        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body, 'the response itself must still be a clean envelope');
        $this->assertSame(['ok' => true], $body['data']);
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('imbalanced'),
            'losing the buffer itself must be reported distinctly, not read as "nothing to report"'
        );
    }

    public function testNoStrayOutputMeansNoWarningLogged(): void
    {
        $this->invokeWithEnvelope(function () {
            return ['data' => ['ok' => true]];
        });

        $this->assertFalse(
            $this->logHandler->hasWarningThatContains('stray output'),
            'a clean call must not log a false-positive warning'
        );
    }

    private function invokeWithEnvelope(callable $endpoint)
    {
        $controller = ContentApiController::create();
        $method = new ReflectionMethod(ContentApiController::class, 'withEnvelope');
        $method->setAccessible(true);

        return $method->invoke($controller, $endpoint);
    }
}
