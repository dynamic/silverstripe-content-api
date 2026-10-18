<?php

/**
 * PHPUnit bootstrap: the CMS test bootstrap, after dropping the config cache.
 *
 * The test kernel shares its config cache (the `configcache` namespace has no
 * `_tests` bucket, unlike the class manifest) with the live kernel. So a live
 * database build run before the suite (local-ci's dev/build gate) leaves a
 * config without the TestOnly stubs, and the suite then fails with tables
 * named after a stub's class and missing relations (#277). Dropping that one
 * cache makes the test kernel rebuild its config, without putting the whole
 * run in flush mode (which on SS5 would flush every Flushable on every test
 * request).
 */

if (defined('TEMP_PATH') && is_dir(TEMP_PATH . '/configcache')) {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(TEMP_PATH . '/configcache', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
}

// A standalone checkout has its own vendor/; a vendor/ checkout uses the
// host project's.
$cmsBootstrap = is_file(__DIR__ . '/../vendor/silverstripe/cms/tests/bootstrap.php')
    ? __DIR__ . '/../vendor/silverstripe/cms/tests/bootstrap.php'
    : __DIR__ . '/../../../silverstripe/cms/tests/bootstrap.php';

require $cmsBootstrap;
