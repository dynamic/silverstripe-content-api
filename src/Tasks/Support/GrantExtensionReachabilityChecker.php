<?php

namespace Dynamic\ContentApi\Tasks\Support;

use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Diagnostic for #103: `ContentApiGrantExtension` grants a verb's `can*()`
 * hook via `DataObject::extendedCan()`, which only takes effect if the
 * owning class's own `can*()` implementation actually calls `extendedCan()`
 * somewhere in its chain — `SiteTree`'s own `canView()`/`canEdit()`/
 * `canCreate()`/`canDelete()` all do, but a subclass that hard-overrides
 * one (FoxyStripe's `ProductPage` is the real-world case #103 was filed
 * against) can skip straight to its own logic, silently defeating the
 * grant with no error and nothing in the module's own diagnostics until
 * now.
 *
 * Reflection-based, not a live behavioral probe: for every concrete
 * `DataObject` subclass that both carries `ContentApiGrantExtension` and
 * declares its own (uninherited) `content_api_access`/`api_access` for a
 * verb — the exact same population {@see ContentApiGrantExtension::grant()}
 * itself checks — resolves the `can*()` method PHP will actually call
 * (following normal inheritance) and reads its declaring class's source
 * for a literal `extendedCan(` call.
 *
 * Necessarily a heuristic, not a proof: misses a call routed through an
 * intermediate helper method one level removed from the `can*()` method
 * itself, and can't distinguish a real call from one that's been
 * commented out. Chosen over a live behavioral probe (attach a
 * forced-veto extension at runtime, check whether a scratch record's
 * answer reflects it) because that approach needs a real `Member` holding
 * `CONTENT_API_ACCESS` to be meaningful, and constructing and safely
 * discarding one is real complexity and blast radius for a read-only
 * diagnostic that should be safe to run against a live database. If
 * reflection's blind spots turn out to matter in practice, that tradeoff
 * is worth revisiting.
 */
class GrantExtensionReachabilityChecker
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ClassRegistry $registry = null;

    /**
     * Verb => the `can*()` hook {@see ContentApiGrantExtension} answers it
     * through. `update`/`action` both resolve to `canEdit()` — matches
     * {@see ContentApiGrantExtension::canEdit()}'s own `grant(['update',
     * 'action'], ...)` call.
     */
    private const VERB_METHODS = [
        'read' => 'canView',
        'create' => 'canCreate',
        'update' => 'canEdit',
        'action' => 'canEdit',
        'delete' => 'canDelete',
    ];

    /**
     * @return array<int, array{class: string, verb: string, method: string, declaringClass: string}>
     *   one entry per (class, verb) pair the extension would grant but
     *   whose resolved `can*()` method's source has no `extendedCan(` call
     */
    public function check(): array
    {
        $findings = [];
        $checkedMethods = [];

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $className) {
            if (!$this->carriesGrantExtension($className)) {
                continue;
            }

            foreach ($this->registry->ownAccessVerbs($className) as $verb) {
                $method = self::VERB_METHODS[$verb] ?? null;

                if ($method === null) {
                    continue;
                }

                // read/update/action can all point at the same resolved
                // method on one class — check each (class, method) pair
                // once regardless of how many verbs reach it.
                $dedupeKey = $className . '::' . $method;

                if (isset($checkedMethods[$dedupeKey])) {
                    continue;
                }

                $checkedMethods[$dedupeKey] = true;

                if (!method_exists($className, $method)) {
                    continue;
                }

                if (!$this->methodCallsExtendedCan($className, $method)) {
                    $findings[] = [
                        'class' => $className,
                        'verb' => $verb,
                        'method' => $method,
                        'declaringClass' => $this->declaringClass($className, $method),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Inherited, not uninherited: an extension applied to an ancestor
     * (`SiteTree`, `BaseElement`) is genuinely active on every subclass —
     * matches `ContentApiGrantExtension`'s own real runtime behavior, not
     * the class-access-declaration check below (which is deliberately
     * uninherited).
     */
    protected function carriesGrantExtension(string $className): bool
    {
        $extensions = (array) Config::inst()->get($className, 'extensions');

        return in_array(ContentApiGrantExtension::class, $extensions, true);
    }

    protected function methodCallsExtendedCan(string $className, string $method): bool
    {
        $source = $this->methodSource($className, $method);

        // Couldn't read the source (unusual — e.g. a compiled/phar class)
        // — not this checker's job to guess, so don't flag a false
        // positive over a limitation of the check itself.
        return $source === null || str_contains($source, 'extendedCan');
    }

    protected function declaringClass(string $className, string $method): string
    {
        try {
            return (new ReflectionClass($className))->getMethod($method)->getDeclaringClass()->getName();
        } catch (ReflectionException) {
            return $className;
        }
    }

    protected function methodSource(string $className, string $method): ?string
    {
        try {
            $reflectionMethod = (new ReflectionClass($className))->getMethod($method);
        } catch (ReflectionException) {
            return null;
        }

        return $this->extractSource($reflectionMethod);
    }

    protected function extractSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();

        if ($file === false || !is_readable($file)) {
            return null;
        }

        $lines = file($file);
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($lines === false || $start === false || $end === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
