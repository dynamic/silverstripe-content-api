<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Assets\AssetService;
use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use SilverStripe\Assets\File;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Asset endpoints:
 *
 * - `POST assets` — upload. Either JSON with `base64`, or
 *   `multipart/form-data` with a `file` part plus meta as form fields
 *   (`filename` defaults to the uploaded file's name). Population-gated.
 * - `GET assets/$ID` — read one (numeric or ext:), returns the record plus
 *   `url` and `hash`.
 */
class AssetHandler
{
    use Injectable;

    private static array $dependencies = [
        'assets' => '%$' . AssetService::class,
        'policy' => '%$' . PermissionPolicy::class,
        'registry' => '%$' . ClassRegistry::class,
        'serializer' => '%$' . RecordSerializer::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
        'environmentGate' => '%$' . EnvironmentGate::class,
        'reader' => '%$' . RecordsHandler::class,
    ];

    public ?AssetService $assets = null;

    public ?PermissionPolicy $policy = null;

    public ?ClassRegistry $registry = null;

    public ?RecordSerializer $serializer = null;

    public ?ExternalIdResolver $externalIds = null;

    public ?EnvironmentGate $environmentGate = null;

    public ?RecordsHandler $reader = null;

    public function upload(HTTPRequest $request, AuthContext $context): array
    {
        $this->policy->checkPopulateAccess($context->member);
        $this->environmentGate->checkPopulationAllowed();

        [$binary, $meta] = $this->extractUpload($request);

        // Resolve the class exactly as ingest() will (same filename
        // normalisation, and the existing record's class on overwrite/skip) so
        // the access check gates the class actually written, not a re-parse.
        $targetClass = $this->assets->resolveTargetClass($meta);
        $this->policy->checkClassAccess(
            $this->governingAssetClass($targetClass),
            'create',
            $context->member
        );

        if (!File::singleton()->canCreate($context->member)) {
            throw new ApiError(ErrorCode::FORBIDDEN_RECORD, 'Not allowed to create files.');
        }

        return Versioned::withVersionedMode(function () use ($binary, $meta) {
            Versioned::set_stage(Versioned::DRAFT);

            $result = $this->assets->ingest($binary, $meta);
            $record = $result['record'];

            return [
                'data' => $this->serializeAsset($record) + ['existed' => $result['existed']],
                'meta' => ['operation' => $result['existed'] ? 'updated' : 'created'],
                'status' => $result['existed'] ? 200 : 201,
            ];
        });
    }

    public function read(HTTPRequest $request, AuthContext $context): array
    {
        // Coarse permission gate before the lookup, so a member without content
        // API access is refused without learning whether the ID exists. The
        // class-level verb check needs the record's actual class, so it runs
        // after the fetch below.
        $this->policy->checkAccess($context->member);

        return Versioned::withVersionedMode(function () use ($request, $context) {
            Versioned::set_stage(Versioned::DRAFT);

            $record = $this->reader->fetchRecord(File::class, (string) $request->param('ID'));

            // Assets carry no classRef in the URL, so gate against the record's
            // actual class (e.g. Image), resolved via the ancestry walk.
            $this->policy->checkClassAccess(
                $this->governingAssetClass(get_class($record)),
                'read',
                $context->member
            );
            $this->policy->checkRecordAccess($record, 'read', $context->member);

            return [
                'data' => $this->serializeAsset($record),
            ];
        });
    }

    /**
     * Resolve the class that governs API access for an asset record. Asset
     * endpoints take no classRef, so walk the concrete class's ancestry
     * (e.g. Image → File) and return the nearest class that is actually
     * granted access; fall back to File so a fully-ungranted asset still
     * fails the deny-by-default check.
     */
    protected function governingAssetClass(string $concreteClass): string
    {
        $candidate = $concreteClass;

        while ($candidate) {
            if ($this->registry->accessVerbs($candidate) !== []) {
                return $candidate;
            }

            if ($candidate === File::class) {
                break;
            }

            $candidate = get_parent_class($candidate) ?: null;
        }

        return File::class;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} binary + meta
     */
    protected function extractUpload(HTTPRequest $request): array
    {
        $contentType = (string) $request->getHeader('Content-Type');

        // #130: dryRun is a `POST batch` feature only. Reject rather than
        // silently ignore — a caller who set "dryRun": true reasonably
        // believes nothing will be written, and asset ingestion has no
        // dry-run support at all. Checked for both request shapes below,
        // before any binary is read.
        if (filter_var($request->postVar('dryRun'), FILTER_VALIDATE_BOOLEAN)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"dryRun" is only supported on "POST batch".');
        }

        if (str_contains($contentType, 'multipart/form-data')) {
            $upload = $_FILES['file'] ?? null;

            if (!$upload || !is_uploaded_file($upload['tmp_name'] ?? '')) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    'Multipart upload requires a "file" part.'
                );
            }

            $binary = (string) file_get_contents($upload['tmp_name']);

            $meta = array_filter([
                'filename' => $request->postVar('filename') ?: ($upload['name'] ?? ''),
                'folder' => $request->postVar('folder'),
                'title' => $request->postVar('title'),
                'externalId' => $request->postVar('externalId'),
                'conflict' => $request->postVar('conflict'),
            ], fn ($value) => $value !== null && $value !== '');

            if ($request->postVar('publish') !== null) {
                $meta['publish'] = filter_var($request->postVar('publish'), FILTER_VALIDATE_BOOLEAN);
            }

            return [$binary, $meta];
        }

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        // See the multipart branch above for why this is rejected outright.
        if (!empty($body['dryRun'])) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"dryRun" is only supported on "POST batch".');
        }

        if (empty($body['base64'])) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'JSON asset upload requires "base64" content (or send multipart/form-data).'
            );
        }

        $binary = base64_decode((string) $body['base64'], true);

        if ($binary === false) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"base64" is not valid base64.');
        }

        unset($body['base64']);

        return [$binary, $body];
    }

    protected function serializeAsset(DataObject $record): array
    {
        /** @var File $record */
        $data = $this->serializer->serialize($record);

        $data['filename'] = $record->getFilename();
        $data['url'] = $record->getURL();
        $data['hash'] = $record->getHash();

        return $data;
    }
}
