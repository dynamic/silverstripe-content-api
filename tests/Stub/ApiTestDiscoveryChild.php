<?php

namespace Dynamic\ContentApi\Tests\Stub;

/**
 * Concrete subclass of ApiTestDiscoveryRoot with no api_access of its own —
 * the thing discovery_roots is meant to expose without a hand-written
 * models/api_access block.
 */
class ApiTestDiscoveryChild extends ApiTestDiscoveryRoot
{
    private static string $table_name = 'ContentApi_ApiTestDiscoveryChild';
}
