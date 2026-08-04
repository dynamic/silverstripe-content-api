<?php

namespace Dynamic\ContentApi\Tests\Stub;

/**
 * Concrete intermediate class between ApiTestDiscoveryRoot and
 * ApiTestDiscoveryGrandchild — gives refFor()'s ancestry walk and
 * discovery_exclude's subclass-expansion something real to climb/exclude
 * through.
 *
 * Deliberately concrete, not PHP-`abstract`: SapphireTest's temp-DB table
 * builder (SilverStripe\ORM\Connect\TableBuilder) instantiates every
 * discovered DataObject subclass regardless of testMode, and fails on a
 * literal `abstract class` ("Cannot instantiate abstract class ..."). The
 * production abstract-filtering in ClassRegistry::discoveredModels() (via
 * ReflectionClass::isInstantiable()) is still correct and needed for real
 * abstract DataObject base classes elsewhere — it just can't be exercised
 * by an integration-style test in this harness.
 */
class ApiTestDiscoveryMiddle extends ApiTestDiscoveryRoot
{
    private static string $table_name = 'ContentApi_ApiTestDiscoveryMiddle';
}
