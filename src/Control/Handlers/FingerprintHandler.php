<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Verify\FingerprintService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;

/**
 * `GET fingerprint` (#131) — a deterministic, path-keyed snapshot of the
 * site's content, suitable for diffing at every gate of a batch/restructure
 * (before/after, same environment) and across environments (a local
 * rehearsal vs. production ahead of a replay). See
 * `Dynamic\ContentApi\Verify\FingerprintService` for the actual build
 * logic and the reachability invariant this exists to assert.
 */
class FingerprintHandler
{
    use Injectable;

    private static array $dependencies = [
        'policy' => '%$' . PermissionPolicy::class,
        'fingerprint' => '%$' . FingerprintService::class,
    ];

    public ?PermissionPolicy $policy = null;

    public ?FingerprintService $fingerprint = null;

    public function handle(HTTPRequest $request, AuthContext $context): array
    {
        // Coarse gate before any class/record-level work — matching
        // AssetHandler::read()'s precedent for an endpoint whose target
        // classes aren't known until after a lookup. Per-class read
        // access is then enforced by FingerprintService itself, which
        // reports an unreadable class in `skipped` rather than failing
        // the whole call — the endpoint's value is being usable even
        // when only part of a site's classes are content-api-exposed.
        $this->policy->checkAccess($context->member);

        $classesParam = trim((string) $request->getVar('classes'));
        $classRefs = $classesParam !== '' ? array_map('trim', explode(',', $classesParam)) : null;
        $includeIds = filter_var($request->getVar('includeIds'), FILTER_VALIDATE_BOOLEAN);

        $result = $this->fingerprint->build($classRefs, $includeIds);

        return [
            'data' => [
                'pages' => $result['pages'],
                'related' => $result['related'],
                'totals' => $result['totals'],
                'violations' => $result['violations'],
            ],
            // `skipped` in meta, not data — it's about what COULDN'T be
            // fingerprinted (a class this token can't read, or a section
            // that doesn't apply), not content itself; keeping it out of
            // `data` means a diff of two fingerprints from callers with
            // different permissions still compares like-for-like content.
            'meta' => ['skipped' => $result['skipped']],
        ];
    }
}
