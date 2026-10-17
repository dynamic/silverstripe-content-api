<?php

namespace Dynamic\ContentApi\Tests\Stub;

use RuntimeException;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Throws from a chosen write/publish/unpublish hook for a record with a
 * chosen Title, to prove a mid-operation failure leaves nothing half
 * applied (#238). Disarmed unless a test sets the static.
 */
class ApiTestExplodingHooksExtension extends Extension implements TestOnly
{
    /**
     * @var array<string, string|null> hook => Title that makes it throw
     */
    public static array $explodeOn = ['write' => null, 'publish' => null, 'unpublish' => null];

    public static function disarm(): void
    {
        static::$explodeOn = ['write' => null, 'publish' => null, 'unpublish' => null];
    }

    private function maybeExplode(string $hook): void
    {
        $title = static::$explodeOn[$hook] ?? null;

        if ($title !== null && $this->getOwner()->Title === $title) {
            throw new RuntimeException(sprintf('Exploding %s hook for "%s".', $hook, $title));
        }
    }

    protected function onBeforeWrite(): void
    {
        $this->maybeExplode('write');
    }

    protected function onBeforePublish(): void
    {
        $this->maybeExplode('publish');
    }

    protected function onAfterUnpublish(): void
    {
        $this->maybeExplode('unpublish');
    }
}
