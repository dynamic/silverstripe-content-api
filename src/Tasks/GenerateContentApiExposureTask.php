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
 * (comma-separate for more than one root — `root=Page\Foo,Page\Bar`, no space
 * after the comma; add `exclude=` the same way to skip one or more subtrees;
 * add `write=1` to write the output to the default generated-config path, or
 * `write=<path>` for a specific one, instead of printing to stdout). Every
 * value must be `key=value` — a bare flag, a repeated `root=`/`exclude=`, or
 * a stray space in a comma list are all refused loudly rather than silently
 * dropping part of what was asked for; see `run()`'s own comments for why
 * each one is a real hazard, not just a style preference.
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
        // Any CLI argument without a `key=value` shape lands in the
        // framework's own 'args' bucket instead of a named var (confirmed
        // against CLIRequestBuilder::cleanEnvironment()) — silently, with no
        // error of its own. Two ways an operator hits this: a bare `write`
        // flag carried over from branch 2's --write habit (never reaches
        // getVar('write') at all, so the task would otherwise fall through
        // to printing YAML instead of writing it); or a stray space in
        // root=A, B (the shell splits it into two argv tokens, so only "A"
        // reaches root= and "B" would otherwise be silently dropped). Refuse
        // loudly rather than risk a scaffold that's silently missing a class
        // the operator asked for — the whole safety model here is "a human
        // reviews this diff," which only works if the diff is actually
        // complete.
        $strayArgs = (array) $request->getVar('args');

        if ($strayArgs !== []) {
            echo "Unrecognized argument(s): " . implode(', ', $strayArgs) . "\n";
            echo "Every value must be key=value — use write=1 (not a bare write), and no space "
                . "after a comma in root=/exclude= (root=A,B, not root=A, B).\n";

            return;
        }

        // A THIRD way to hit the same silent-narrowing hazard: repeating
        // root= or exclude= entirely, carrying over branch 2's repeatable
        // --root/--exclude. Every repeat is individually well-formed
        // key=value, so none of it lands in 'args' above — CLIRequestBuilder
        // just array_merge()s them, and only the last survives with no
        // signal at all. $_GET['args'] doesn't exist for a plain HTTP
        // request, so this only ever fires for a real `sake` CLI invocation.
        $rawArgv = (array) ($_SERVER['argv'] ?? []);

        foreach (['root', 'exclude'] as $key) {
            $count = count(array_filter(
                $rawArgv,
                fn ($arg) => is_string($arg) && str_starts_with($arg, $key . '=')
            ));

            if ($count > 1) {
                echo "{$key}= was passed {$count} times — only the last one would be used, the "
                    . "rest silently dropped. Comma-separate multiple values instead: "
                    . "{$key}=A,B (not {$key}=A {$key}=B).\n";

                return;
            }
        }

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
