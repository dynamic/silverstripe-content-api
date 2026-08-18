<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;

/**
 * #186: `ContentApiTestCase::setUp()` pins `SilverStripe\Assets\File`/`Image`
 * to `api_access: false` so the suite doesn't inherit whatever a host
 * project's own `content-api.yml` happens to grant those two real (non-stub)
 * classes. `AssetsTest` alone doesn't prove that pin holds — this project's
 * own SS5 testbed never granted `Image` its own `api_access` in the first
 * place, so on this particular host every `AssetsTest` assertion would pass
 * identically whether the pin exists or not (#186 only ever reproduced
 * against the SS6 testbed, which does grant `Image`). This test asserts the
 * pin directly and UNINHERITED, so a future regression — the two
 * `Config::modify()` calls being dropped, reordered after a test's own
 * override, or narrowed — fails on every host that runs the suite, not only
 * one that happens to also grant `Image`.
 *
 * Deliberately its own test class rather than an assertion inside
 * `AssetsTest`: `AssetsTest::setUp()` immediately overrides `File`'s
 * `api_access` to `'read,create'`, so a check running after that setUp
 * would observe the override, not the base-class pin it's meant to guard.
 */
class AssetApiAccessPinTest extends ContentApiTestCase
{
    public function testFileAndImageArePinnedToNoAccessByDefault(): void
    {
        $this->assertFalse(
            Config::inst()->get(File::class, 'api_access', Config::UNINHERITED),
            'ContentApiTestCase::setUp() should pin File::api_access to false'
        );
        $this->assertFalse(
            Config::inst()->get(Image::class, 'api_access', Config::UNINHERITED),
            'ContentApiTestCase::setUp() should pin Image::api_access to false'
        );
    }
}
