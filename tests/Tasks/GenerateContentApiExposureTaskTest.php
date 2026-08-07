<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\GenerateContentApiExposureTask;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapter-only coverage for branch `2`'s SS6 entry point — same pattern as
 * {@see \Dynamic\ContentApi\Tests\Tasks\CheckGrantExtensionReachabilityTaskTest}.
 * The scaffolding logic itself is exercised branch-neutrally in
 * {@see \Dynamic\ContentApi\Tests\Tasks\Support\ExposureScaffolderTest}.
 */
class GenerateContentApiExposureTaskTest extends SapphireTest
{
    public function testPrintsGeneratedYamlToStdoutByDefault(): void
    {
        [$exitCode, $output] = $this->runTask(['--root' => [ApiTestElement::class]]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(ApiTestElement::class . ':', $output);
        $this->assertStringContainsString('AUTO-GENERATED', $output);
    }

    public function testMissingRootIsAnInvalidUsageError(): void
    {
        [$exitCode, $output] = $this->runTask([]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('At least one --root is required', $output);
    }

    public function testWriteOptionWritesToDiskInsteadOfStdout(): void
    {
        // Project-relative on purpose — the task always resolves --write
        // against BASE_PATH (see its own docblock), so the test target must
        // live there too rather than an OS temp dir that may sit outside it.
        $relative = '_config/content-api-exposure-test-' . uniqid() . '.yml';
        $path = BASE_PATH . '/' . $relative;

        try {
            [$exitCode, $output] = $this->runTask([
                '--root' => [ApiTestElement::class],
                '--write' => $relative,
            ]);

            $this->assertSame(Command::SUCCESS, $exitCode);
            $this->assertStringContainsString('Wrote', $output);
            $this->assertStringNotContainsString(ApiTestElement::class . ':', $output);
            $this->assertFileExists($path);
            $this->assertStringContainsString(ApiTestElement::class . ':', file_get_contents($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function runTask(array $options): array
    {
        $task = GenerateContentApiExposureTask::create();
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));
        $buffer = new BufferedOutput();
        $output = PolyOutput::create(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, false, $buffer);

        $exitCode = $task->run($input, $output);

        return [$exitCode, $buffer->fetch()];
    }
}
