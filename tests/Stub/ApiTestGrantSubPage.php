<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * ApiTestPage subclass that declares NO `content_api_access`/`api_access`
 * of its own — mirrors the real-world shape of a `Page` subclass
 * (`ProductPage`, `UserDefinedForm`, `ErrorPage`, `RedirectorPage`, ...)
 * that only inherits its ancestor's declared value.
 *
 * ClassRegistry::accessVerbs() (the class-level gate) DOES inherit
 * ApiTestPage's declared verbs for this class — that's deliberate, existing
 * behaviour. ContentApiGrantExtensionTest uses this stub to prove the
 * record-level grant does NOT: ClassRegistry::ownAccessVerbs() must return
 * nothing for this class regardless of what ApiTestPage declares, so
 * ContentApiGrantExtension never answers a can*() hook for it.
 */
class ApiTestGrantSubPage extends ApiTestPage implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantSubPage';
}
