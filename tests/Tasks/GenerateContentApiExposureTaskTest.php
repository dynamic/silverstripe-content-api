<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\GenerateContentApiExposureTask;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Adapter-only coverage for this branch's legacy `run($request)` entry
 * point: request-var parsing (`splitVar()`), `--flag` -> `key=value`
 * message translation (`translateFlagSyntax()`), and the `write=` file
 * output branch — none of which `Tasks/Support/ExposureScaffolderTest`
 * covers, since that suite calls `ExposureScaffolder::generate()` directly
 * with a PHP array, never through this adapter's request-var parsing or
 * its `write=` handling. Same shape as `SetupServiceAccountTaskTest`/
 * `CheckGrantExtensionReachabilityTaskTest` — see #115/#118.
 */
class GenerateContentApiExposureTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    private array $writtenPaths = [];

    private array $writtenDirs = [];

    protected function tearDown(): void
    {
        // write= with no explicit path writes into the HOST project's own
        // _config/ (BASE_PATH, not this module's), since that's exactly
        // what the task is for — clean up anything a test actually wrote,
        // unconditionally, so a test failure never leaves generated config
        // behind for the next dev/build to pick up. Files first, then any
        // directory the task created for them.
        foreach ($this->writtenPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->writtenDirs as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        parent::tearDown();
    }

    public function testRootProducesYamlOnStdout(): void
    {
        $output = $this->runTask(['root' => ApiTestElement::class]);

        $this->assertStringContainsString(ApiTestElement::class . ':', $output);
        $this->assertStringContainsString('AUTO-GENERATED', $output);
    }

    /**
     * `root=A,B` -> both classes scaffolded — splitVar()'s comma-split,
     * not covered by ExposureScaffolderTest (which passes a PHP array
     * directly, never a request var string).
     */
    public function testCommaSeparatedRootsAreAllScaffolded(): void
    {
        $output = $this->runTask(['root' => ApiTestElement::class . ',' . Member::class]);

        $this->assertStringContainsString(ApiTestElement::class . ':', $output);
        // Member is denylisted, so it's never emitted as its own block —
        // this only confirms both names reached generate() as separate
        // roots, not that Member produced output.
        $this->assertStringNotContainsString(Member::class . ':', $output);
    }

    /**
     * exclude= also comma-splits, and actually removes the named class
     * from the scaffolded output.
     */
    public function testCommaSeparatedExcludesAreApplied(): void
    {
        $output = $this->runTask([
            'root' => ApiTestElement::class,
            'exclude' => ApiTestElement::class,
        ]);

        $this->assertStringNotContainsString(ApiTestElement::class . ':', $output);
    }

    public function testOmittingRootUsesThisBranchsSyntax(): void
    {
        $output = $this->runTask([]);

        $this->assertStringContainsString('At least one root is required', $output);
        $this->assertStringNotContainsString('--root', $output);
    }

    public function testUnknownRootClassUsesThisBranchsSyntax(): void
    {
        $output = $this->runTask(['root' => 'Totally\\Not\\A\\Real\\Class']);

        $this->assertStringContainsString('Unknown root class', $output);
        $this->assertStringNotContainsString('--root', $output);
    }

    public function testUnknownExcludeClassUsesThisBranchsSyntax(): void
    {
        $output = $this->runTask([
            'root' => ApiTestElement::class,
            'exclude' => 'Totally\\Not\\A\\Real\\Class',
        ]);

        $this->assertStringContainsString('Unknown exclude class', $output);
        $this->assertStringNotContainsString('--exclude', $output);
    }

    /**
     * Bare `write=1` writes to GenerateContentApiExposureTask::DEFAULT_WRITE_PATH
     * under BASE_PATH, rather than printing to stdout.
     */
    public function testBareWriteUsesTheDefaultPath(): void
    {
        $path = BASE_PATH . '/_config/999-content-api-generated.yml';
        $this->writtenPaths[] = $path;

        $output = $this->runTask(['root' => ApiTestElement::class, 'write' => '1']);

        $this->assertStringContainsString('Wrote ' . $path, $output);
        $this->assertStringNotContainsString(ApiTestElement::class . ':', $output, 'yaml goes to the file, not stdout');
        $this->assertFileExists($path);
        $this->assertStringContainsString(ApiTestElement::class . ':', (string) file_get_contents($path));
    }

    /**
     * `write=<path>` writes to that project-relative path instead of the
     * default, including creating an intermediate directory that doesn't
     * exist yet.
     */
    public function testExplicitWritePathIsUsedAndDirectoryIsCreated(): void
    {
        $relative = '_config/adapter-test-subdir/generated.yml';
        $path = BASE_PATH . '/' . $relative;
        $this->writtenPaths[] = $path;
        $this->writtenDirs[] = dirname($path);

        $output = $this->runTask(['root' => ApiTestElement::class, 'write' => $relative]);

        $this->assertStringContainsString('Wrote ' . $path, $output);
        $this->assertFileExists($path);
    }

    protected function runTask(array $vars): string
    {
        $task = GenerateContentApiExposureTask::create();
        $request = new HTTPRequest('GET', '/', $vars);

        ob_start();
        $task->run($request);

        return ob_get_clean();
    }
}
