<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\TaskResult;
use Dynamic\ContentApi\Tasks\Support\TaskResultRenderer;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class TaskResultRendererTest extends SapphireTest
{
    public function testSuccessLinesAreNotErrorStyled(): void
    {
        $buffer = new BufferedOutput();
        $output = $this->polyOutput($buffer);

        $exitCode = TaskResultRenderer::render($output, new TaskResult(TaskStatus::Success, ['all good']));

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString("\e[", $buffer->fetch());
    }

    public function testFailureLinesAreErrorStyled(): void
    {
        $buffer = new BufferedOutput();
        $output = $this->polyOutput($buffer);

        $exitCode = TaskResultRenderer::render($output, new TaskResult(TaskStatus::Failure, ['it broke']));

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("\e[", $buffer->fetch());
    }

    public function testInvalidMapsToCommandInvalid(): void
    {
        $exitCode = TaskResultRenderer::render(
            $this->polyOutput(new BufferedOutput()),
            new TaskResult(TaskStatus::Invalid, ['--email is required'])
        );

        $this->assertSame(Command::INVALID, $exitCode);
    }

    /**
     * A blank line inside an otherwise-error result must stay unwrapped —
     * an empty `<error></error>` pair would render as a stray styled blank
     * segment.
     */
    public function testBlankLinesInsideAnErrorResultAreNotWrapped(): void
    {
        $buffer = new BufferedOutput();
        $output = $this->polyOutput($buffer);

        TaskResultRenderer::render($output, new TaskResult(TaskStatus::Failure, ['first', '', 'second']));

        $lines = explode("\n", rtrim($buffer->fetch(), "\n"));
        $this->assertSame('', $lines[1]);
    }

    private function polyOutput(OutputInterface $wrapped): PolyOutput
    {
        return PolyOutput::create(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, true, $wrapped);
    }
}
