<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ExposureScaffolder;
use InvalidArgumentException;
use SilverStripe\Dev\BuildTask;

/**
 * Branch `1` (this branch) entry point. All business logic lives in
 * {@see ExposureScaffolder} — see #115/#118; this adapter only translates
 * the legacy `BuildTask::run($request)` request vars and echoes the
 * result. Branch `2`'s SS6 copy of this file wraps the same service around
 * `execute(InputInterface, PolyOutput): int` instead — there is no shared
 * entry point between the two branches, so a business-logic fix belongs in
 * `ExposureScaffolder` (shared) rather than either adapter.
 *
 * Usage: `sake dev/tasks/GenerateContentApiExposure root=DNADesign\Elemental\Models\BaseElement`
 * (comma-separate for more than one root, e.g. `root=Page\Foo,Page\Bar`; add
 * `exclude=` the same way to skip one or more subtrees; add `write=1` to write
 * the output to the default generated-config path, or `write=<path>` for a
 * specific one, instead of printing to stdout).
 */
class GenerateContentApiExposureTask extends BuildTask
{
    private static string $segment = 'GenerateContentApiExposure';

    protected $title = 'Generate content-api exposure config';

    protected $description = 'Introspects one or more classes (and their concrete subclasses) '
        . 'and prints starting-point api_access/api_writable_fields/api_writable_relations YAML '
        . 'for each — a scaffold to review and paste into your own project config, not something '
        . 'this task applies itself. See #115/#118.';

    /**
     * Default write target for `write=1` passed with no explicit path — a
     * project's own `write=some/other/path.yml` always wins; this is only
     * the fallback when the var is bare. Numeric prefix keeps SilverStripe's
     * `_config/` manifest load order predictable without needing an
     * `Only`/`After` guard, since — unlike `essentials.yml`/`linkfield.yml`
     * — this file's content isn't gated on another package being installed.
     * Matches branch `2`'s own default verbatim.
     */
    private const DEFAULT_WRITE_PATH = '_config/999-content-api-generated.yml';

    public function run($request)
    {
        $roots = $this->splitVar($request->getVar('root'));
        $excludes = $this->splitVar($request->getVar('exclude'));

        try {
            $yaml = ExposureScaffolder::create()->generate($roots, $excludes);
        } catch (InvalidArgumentException $e) {
            // ExposureScaffolder is shared with branch `2`'s --flag-based
            // adapter, so its own exception messages use that syntax —
            // translate to this branch's `key=value` request-var syntax
            // before it reaches the operator.
            echo $this->translateFlagSyntax($e->getMessage()) . "\n";

            return;
        }

        $writeVar = $request->getVar('write');

        if ($writeVar === null) {
            echo $yaml . "\n";

            return;
        }

        $path = BASE_PATH . '/' . ltrim(
            $writeVar === '1' || $writeVar === '' ? self::DEFAULT_WRITE_PATH : (string) $writeVar,
            '/'
        );
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            echo "Could not create directory \"{$dir}\".\n";

            return;
        }

        if (file_put_contents($path, $yaml) === false) {
            echo "Could not write \"{$path}\".\n";

            return;
        }

        echo "Wrote {$path}\n";
        echo "Review the diff, then dev/build flush=1 before it takes effect.\n";
    }

    /**
     * `root=A,B` -> `['A', 'B']`; a null/empty var -> `[]`. No trimming
     * quirks to handle beyond a plain comma split — a class FQCN never
     * legitimately contains a comma.
     *
     * @return string[]
     */
    protected function splitVar(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    protected function translateFlagSyntax(string $message): string
    {
        return str_replace(['--root', '--exclude'], ['root', 'exclude'], $message);
    }
}
