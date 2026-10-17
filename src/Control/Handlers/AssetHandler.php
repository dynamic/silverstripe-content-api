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

        // Authorizes against the record ingest() will actually touch: the
        // existing file for skip/overwrite, else the class the extension maps
        // to, plus `action` when it publishes (AssetService::authorizeIngest()).
        ['meta' => $meta, 'warnings' => $warnings] = $this->assets->authorizeIngest($meta, $context->member);

        return Versioned::withVersionedMode(function () use ($binary, $meta, $warnings) {
            Versioned::set_stage(Versioned::DRAFT);

            $result = $this->assets->ingest($binary, $meta);
            $record = $result['record'];
            $data = $this->serializeAsset($record) + ['existed' => $result['existed']];

            if ($warnings !== []) {
                $data['warnings'] = $warnings;
            }

            return [
                'data' => $data,
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
                $this->assets->governingAssetClass(get_class($record)),
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
     * Report why PHP didn't deliver a multipart upload instead of the generic
     * "requires a file part" (#246): an over-limit file arrives with an
     * UPLOAD_ERR_* code and no temp file, and a body over post_max_size
     * arrives with $_FILES empty.
     *
     * @param array<string, mixed>|null $upload
     * @throws ApiError PAYLOAD_TOO_LARGE | PAYLOAD_INVALID
     */
    protected function assertUploadReceived(?array $upload): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_OK);

        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_TOO_LARGE,
                sprintf(
                    'The uploaded file exceeds the server\'s limit (upload_max_filesize %s).',
                    ini_get('upload_max_filesize')
                )
            );
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($upload === null && $contentLength > $this->bytes((string) ini_get('post_max_size'))) {
            throw new ApiError(
                ErrorCode::PAYLOAD_TOO_LARGE,
                sprintf('The request body exceeds the server\'s limit (post_max_size %s).', ini_get('post_max_size'))
            );
        }

        $messages = [
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded in the "file" part.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        ];

        if (isset($messages[$error])) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, $messages[$error]);
        }
    }

    private function bytes(string $size): int
    {
        $size = trim($size);
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
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
            $this->assertUploadReceived($upload);

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

            // Parsed strictly by AssetService::authorizeIngest() (#239), not
            // here, so a typo is a 400 rather than a silent false.
            if ($request->postVar('publish') !== null) {
                $meta['publish'] = (string) $request->postVar('publish');
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
        // No session grant (#246): File::getURL() grants the current
        // session access to a protected file, which a token client never
        // has, so `url` would look fetchable when it isn't. `visibility`
        // says whether it is: "public" (published) or "protected".
        $data['url'] = $record->File->getURL(false);
        $data['visibility'] = $record->getVisibility();
        $data['hash'] = $record->getHash();

        return $data;
    }
}
