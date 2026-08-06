<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * Deliberately NOT a real `DataObject` subclass, abstract or otherwise:
 * SilverStripe's own `TempDatabase`/`TableBuilder` schema rebuild walks
 * every `DataObject` descendant in the class manifest — `abstract` or
 * not — and unconditionally does `new $dataClass()` on each one to check
 * for `TestOnly` before building its table. PHP fatals hard on
 * instantiating an abstract class at the engine level, before any
 * constructor logic runs, so an actual `abstract class ... extends
 * DataObject` fixture here would crash every phpunit run in the whole
 * project the moment this file is autoloaded — not a limitation of the
 * checker under test, a landmine in the framework's own schema-build
 * step (confirmed empirically; no abstract `DataObject` subclass exists
 * anywhere else in this project's vendor tree, which is exactly why).
 *
 * `GrantExtensionReachabilityCheckerTest` instead invokes
 * `GrantExtensionReachabilityChecker::isConcrete()` directly via
 * reflection, passing this class's name — a real abstract class, just
 * not one the schema builder ever has to instantiate — to prove the
 * `ReflectionClass::isInstantiable()` filter correctly reports `false`
 * for it.
 */
abstract class ApiTestGrantAbstractObject implements TestOnly
{
}
