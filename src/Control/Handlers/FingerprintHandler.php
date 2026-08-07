<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
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
        // classes aren't known until after a lookup. Class- and
        // record-level access are then enforced by FingerprintService
        // itself, per row — a class not exposed to the content API at all
        // is reported in `skipped` (site config, the same for every
        // caller); a class that IS exposed but whose specific row this
        // member can't view (e.g. a draft-only page without
        // VIEW_DRAFT_CONTENT) is simply excluded from that row's section,
        // the same way RecordsHandler::readList() filters a list.
        $this->policy->checkAccess($context->member);

        $classesParam = trim((string) $request->getVar('classes'));
        $classRefs = null;

        if ($classesParam !== '') {
            $classRefs = array_values(array_filter(
                array_map('trim', explode(',', $classesParam)),
                static fn (string $ref): bool => $ref !== ''
            ));

            // "?classes=," (or any value that's all commas/whitespace)
            // isn't "no filter" — it's malformed. Left as $classRefs = []
            // it would silently exclude every section from the response
            // with no error at all, the exact "false no-drift reading"
            // failure mode an unknown ref is already rejected to avoid.
            if ($classRefs === []) {
                throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'classes must name at least one section.');
            }
        }

        $includeIds = filter_var($request->getVar('includeIds'), FILTER_VALIDATE_BOOLEAN);

        // FingerprintService applies class- AND record-level ACL per row
        // (same as RecordsHandler::readList()) — the coarse gate above is
        // only "can this token use the endpoint at all", not "can it see
        // everything the endpoint touches".
        $result = $this->fingerprint->build($classRefs, $includeIds, $context->member);

        return [
            'data' => [
                'pages' => $result['pages'],
                'related' => $result['related'],
                'totals' => $result['totals'],
                'violations' => $result['violations'],
            ],
            // `skipped` in meta, not data — it names a class not exposed
            // to the content API at all (site config, not per-caller) or
            // a section that doesn't apply (no SiteTree installed). It is
            // NOT where row-level, per-caller visibility differences show
            // up — those are silent omissions from `pages`/`related`
            // rows/`violations`, the same as RecordsHandler::readList()'s
            // own list filtering.
            'meta' => ['skipped' => $result['skipped']],
        ];
    }
}
