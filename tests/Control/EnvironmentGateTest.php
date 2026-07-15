<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;

class EnvironmentGateTest extends ContentApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Force population endpoints closed by config so only the env-var
        // override under test can reopen them.
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);
    }

    protected function tearDown(): void
    {
        // Environment has no public "unset"; `false` is what getEnv() returns
        // for a var that was never set, so this restores that state.
        Environment::setEnv('SS_CONTENT_API_ALLOW_POPULATE', false);

        parent::tearDown();
    }

    /**
     * @dataProvider blockingValueProvider
     */
    public function testFalsyValuesDoNotOverrideTheGate(mixed $value): void
    {
        Environment::setEnv('SS_CONTENT_API_ALLOW_POPULATE', $value);

        try {
            (new EnvironmentGate())->checkPopulationAllowed();
            $this->fail('Expected ApiError ENV_FORBIDDEN to be thrown.');
        } catch (ApiError $e) {
            $this->assertSame(ErrorCode::ENV_FORBIDDEN, $e->getErrorCode());
        }
    }

    public static function blockingValueProvider(): array
    {
        return [
            'literal false string' => ['false'],
            'literal 0 string' => ['0'],
            'literal off' => ['off'],
            'literal no' => ['no'],
            'empty string' => [''],
            'whitespace' => ['   '],
            // SilverStripe's real .env loader (M1\Env\Parser) coerces an
            // unquoted `false` to a native PHP bool and `0` to an int — not
            // strings. A string-only check would miss these entirely.
            'native bool false' => [false],
            'native int 0' => [0],
        ];
    }

    /**
     * @dataProvider allowingValueProvider
     */
    public function testTruthyValuesOverrideTheGate(mixed $value): void
    {
        Environment::setEnv('SS_CONTENT_API_ALLOW_POPULATE', $value);

        (new EnvironmentGate())->checkPopulationAllowed();

        // No exception thrown is the assertion; this keeps PHPUnit from
        // flagging the test as risky for having no assertions.
        $this->addToAssertionCount(1);
    }

    public static function allowingValueProvider(): array
    {
        return [
            '1 string' => ['1'],
            'true lowercase' => ['true'],
            'TRUE uppercase' => ['TRUE'],
            'yes' => ['yes'],
            'on' => ['on'],
            'padded' => [' 1 '],
            // The same .env coercion as above, but for the "on" case — and
            // the exact value this class's own error message recommends
            // setting (`SS_CONTENT_API_ALLOW_POPULATE=1`, unquoted, parses
            // as a native int, not a string).
            'native bool true' => [true],
            'native int 1' => [1],
        ];
    }

    public function testUnsetEnvVarDoesNotOverrideTheGate(): void
    {
        Environment::setEnv('SS_CONTENT_API_ALLOW_POPULATE', false);

        try {
            (new EnvironmentGate())->checkPopulationAllowed();
            $this->fail('Expected ApiError ENV_FORBIDDEN to be thrown.');
        } catch (ApiError $e) {
            $this->assertSame(ErrorCode::ENV_FORBIDDEN, $e->getErrorCode());
        }
    }
}
