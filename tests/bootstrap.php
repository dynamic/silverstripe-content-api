<?php

/**
 * PHPUnit bootstrap: the CMS test bootstrap, with the test kernel flushed.
 *
 * The test kernel shares its config cache with the live kernel, and never
 * flushes on its own (SapphireTest::start() mocks a bare `phpunit /` request).
 * So a live database build run before the suite (local-ci's dev/build gate)
 * leaves a config without the TestOnly stubs, and the suite then fails with
 * tables named after a stub's class and missing relations (#277). Asking for
 * a flush makes the test kernel rebuild its manifests and config once per run.
 */

$_GET['flush'] = 1;

// A standalone checkout has its own vendor/; a vendor/ checkout uses the
// host project's.
$cmsBootstrap = is_file(__DIR__ . '/../vendor/silverstripe/cms/tests/bootstrap.php')
    ? __DIR__ . '/../vendor/silverstripe/cms/tests/bootstrap.php'
    : __DIR__ . '/../../../silverstripe/cms/tests/bootstrap.php';

require $cmsBootstrap;
