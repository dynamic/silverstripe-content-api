<?php

namespace Dynamic\ContentApi\Tests\Stub;

/**
 * Concrete leaf under the abstract intermediate class — proves discovery
 * still finds a concrete class even when it isn't a direct child of the
 * configured root, and gives refFor()'s ancestry walk something to climb
 * through for a class that was never itself directly registered.
 */
class ApiTestDiscoveryGrandchild extends ApiTestDiscoveryMiddle
{
    private static string $table_name = 'ContentApi_ApiTestDiscoveryGrandchild';
}
