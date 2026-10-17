<?php

namespace Dynamic\ContentApi\Assets;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\FolderNameFilter;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * First-class asset ingestion — the API replacement for populate's
 * `PopulateFileFrom` handling, minus its fatal flaw: this service ALWAYS
 * returns the full File record (`existed: true` on a hash match), so the
 * caller can wire relations no matter how many times the same asset is sent.
 * (PopulateFactory::populateFile returns bare `true` on hash match, which is
 * why second-run `=>Image` refs break — fixtures recipe issue #39.)
 *
 * Conflict modes when a file already exists at the target path:
 * - `overwrite` (default): replace content (skipped when hashes match)
 * - `skip`: return the existing record untouched
 * - `rename`: store under a deduplicated name, new record
 *
 * Every caller authorizes through authorizeIngest() first, so `POST assets`
 * and composition `assets[]` apply the same verbs to the record actually
 * written.
 */
class AssetService
{
    use Injectable;

    private static array $dependencies = [
        'externalIds' => '%$' . ExternalIdResolver::class,
        'policy' => '%$' . PermissionPolicy::class,
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ExternalIdResolver $externalIds = null;

    public ?PermissionPolicy $policy = null;

    public ?ClassRegistry $registry = null;

    /**
     * Authorize an ingest before anything is written, against the record the
     * ingest will actually touch (#229, #235):
     *
     * - `skip` onto an existing file: `read` (skip writes nothing).
     * - `overwrite` onto an existing file: `update` on its class plus
     *   `canEdit()`.
     * - a new file (no existing one, or `rename`): `create` on the class the
     *   extension maps to plus `canCreate()` with the target folder as
     *   `Parent`; a missing folder also needs Folder::canCreate().
     * - publishing (the default unless `publish` is false): `action` on the
     *   class written. When `publish` was not sent and the class lacks
     *   `action`, the file is written draft-only and an ACTION_VERB_MISSING
     *   warning is returned instead of a refusal. An explicit
     *   `"publish": true` without `action` is refused.
     *
     * Returns `$meta` with `publish` resolved to a bool for ingest(), plus
     * any warnings.
     *
     * @param array<string, mixed> $meta
     * @return array{meta: array<string, mixed>, warnings: array<int, array<string, string>>}
     * @throws ApiError
     */
    public function authorizeIngest(array $meta, Member $member): array
    {
        // A JSON null means "not sent".
        $explicitPublish = isset($meta['publish']) ? $this->publishFlag($meta['publish']) : null;
        $publish = $explicitPublish ?? true;
        $conflict = (string) ($meta['conflict'] ?? 'overwrite');
        $existing = $this->resolveExisting($meta);
        $warnings = [];

        if ($existing && $conflict === 'skip') {
            $this->authorizeOn($existing, 'read', $member);
            $meta['publish'] = false;

            return ['meta' => $meta, 'warnings' => $warnings];
        }

        if ($existing && $conflict === 'overwrite') {
            $this->authorizeOn($existing, 'update', $member);
            $writtenClass = get_class($existing);
        } else {
            $writtenClass = $this->resolveTargetClass($meta);
            $governing = $this->governingAssetClass($writtenClass);
            $this->policy->checkClassAccess($governing, 'create', $member);
            $this->authorizeFolder($meta, $member, $writtenClass);
        }

        if ($publish) {
            $governing = $this->governingAssetClass($writtenClass);

            if ($explicitPublish === null && !in_array('action', $this->registry->accessVerbs($governing), true)) {
                $publish = false;
                $warnings[] = [
                    'code' => ErrorCode::ACTION_VERB_MISSING->value,
                    'message' => sprintf(
                        '%s does not grant "action", so the file was written draft-only. Add "action" to '
                            . 'its api_access config, or send "publish": false to skip publishing.',
                        $governing
                    ),
                    'field' => 'publish',
                ];
            } else {
                $this->policy->checkClassAccess($governing, 'action', $member);

                if ($existing && $conflict === 'overwrite') {
                    $this->policy->checkStageAccess($existing, 'publish', $member);
                }
            }
        }

        $meta['publish'] = $publish;

        return ['meta' => $meta, 'warnings' => $warnings];
    }

    /**
     * The class that governs API access for an asset record. Asset endpoints
     * take no classRef, so walk the concrete class's ancestry (Image → File)
     * and return the nearest class that declares `api_access` /
     * `content_api_access` itself — including an explicit deny. An Image
     * granted on its own (the README quick-start) governs itself; an Image
     * that declares nothing falls through to File; an Image that explicitly
     * denies stays denied. (The walk used to skip any class with no verbs,
     * which turned that explicit deny into File's grant, #236.) Falls back to
     * File so a fully undeclared asset still fails deny-by-default.
     */
    public function governingAssetClass(string $concreteClass): string
    {
        $candidate = $concreteClass;

        while ($candidate) {
            if ($this->registry->declaresAccess($candidate)) {
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
     * Strict boolean parse for an asset `publish` flag: a JSON payload's
     * string `"false"` must not read as true (#239).
     *
     * @throws ApiError PAYLOAD_INVALID
     */
    public function publishFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = match (true) {
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };

        if ($parsed === null) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                '"publish" must be true or false.',
                [['field' => 'publish']]
            );
        }

        return $parsed;
    }

    /**
     * The existing file a skip/overwrite ingest would reuse, read on the
     * draft stage (where ingest writes) so a draft-only file counts. Null for
     * `rename`, an unusable filename, or no file at the path.
     */
    public function resolveExisting(array $meta): ?File
    {
        $targetPath = $this->targetPath($meta);

        if ($targetPath === null || (string) ($meta['conflict'] ?? 'overwrite') === 'rename') {
            return null;
        }

        return Versioned::withVersionedMode(function () use ($targetPath) {
            Versioned::set_stage(Versioned::DRAFT);

            return File::get()->filter('FileFilename', $targetPath)->first();
        });
    }

    /**
     * Class verb plus record can*() for an existing asset, with the class
     * verb read from its governing class.
     */
    protected function authorizeOn(File $record, string $verb, Member $member): void
    {
        $this->policy->checkClassAccess($this->governingAssetClass(get_class($record)), $verb, $member);
        $this->policy->checkRecordAccess($record, $verb, $member);
    }

    /**
     * canCreate() for a new file with its target folder as `Parent`, and
     * Folder::canCreate() when the folder (or part of it) does not exist
     * yet, since Folder::find_or_make() would write it.
     */
    protected function authorizeFolder(array $meta, Member $member, string $writtenClass): void
    {
        $folderPath = trim((string) ($meta['folder'] ?? ''), '/');
        $folder = null;

        if ($folderPath !== '') {
            $folder = $this->findFolder($folderPath);

            // Folder creation is a side effect of the upload, so it is held to
            // the record-level rule only (Folder::canCreate()), not to a
            // class verb on Folder that a project granting just Image would
            // never declare.
            if (!$folder) {
                if (!Folder::singleton()->canCreate($member)) {
                    throw new ApiError(ErrorCode::FORBIDDEN_RECORD, 'Not allowed to create folders.');
                }
            }
        }

        $context = $folder ? ['Parent' => $folder] : [];

        if (!DataObject::singleton($writtenClass)->canCreate($member, $context)) {
            throw new ApiError(ErrorCode::FORBIDDEN_RECORD, 'Not allowed to create files.');
        }
    }

    /**
     * Folder::find_or_make()'s lookup without the create: walk the path one
     * segment at a time on the draft stage, matching a segment by its
     * filtered or raw name the same way. Null when any segment is missing.
     */
    protected function findFolder(string $folderPath): ?Folder
    {
        return Versioned::withVersionedMode(function () use ($folderPath) {
            Versioned::set_stage(Versioned::DRAFT);

            $filter = FolderNameFilter::create();
            $parentID = 0;
            $folder = null;

            foreach (array_filter(preg_split('#[/\\\\]+#', $folderPath) ?: []) as $part) {
                $folder = Folder::get()->filter([
                    'ParentID' => $parentID,
                    'Name' => [$filter->filter($part), $part],
                ])->first();

                if (!$folder) {
                    return null;
                }

                $parentID = (int) $folder->ID;
            }

            return $folder;
        });
    }

    protected function targetPath(array $meta): ?string
    {
        $filename = basename(trim((string) ($meta['filename'] ?? '')));
        $folder = trim((string) ($meta['folder'] ?? ''), '/');

        if ($filename === '' || $filename === '.') {
            return null;
        }

        return $folder !== '' ? "{$folder}/{$filename}" : $filename;
    }

    /**
     * The File subclass a given upload payload will resolve to — the class of
     * an already-stored file at the same path (skip/overwrite reuse it), else
     * the class SilverStripe maps the extension to. Shares the exact filename
     * normalisation and conflict handling `ingest()` uses so an access check
     * computed from this matches the record actually written. Defaults to File
     * when the filename is unusable (ingest() then surfaces the PAYLOAD_INVALID).
     *
     * @param array{filename?: string, folder?: string, conflict?: string} $meta
     */
    public function resolveTargetClass(array $meta): string
    {
        $filename = basename(trim((string) ($meta['filename'] ?? '')));

        if ($filename === '' || $filename === '.') {
            return File::class;
        }

        // `rename` never reuses the existing record — it writes a new record of
        // the extension-derived class — so resolveExisting() returns null for
        // it and only skip/overwrite adopt the existing record's class.
        $existing = $this->resolveExisting($meta);

        if ($existing) {
            return get_class($existing);
        }

        return File::get_class_for_file_extension(
            strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))
        );
    }

    /**
     * @param array{filename: string, folder?: string, title?: string,
     *   externalId?: string, conflict?: string, publish?: bool} $meta
     * @return array{record: File, existed: bool}
     * @throws ApiError
     */
    public function ingest(string $binary, array $meta): array
    {
        // #204: this was the one write path in the module that let
        // `ValidationException` escape unmapped — `DBFile::setFromString()`
        // (both call sites below) throws it directly via
        // `assertFilenameValid()` for a disallowed extension
        // (`File.allowed_extensions`), surfacing as an unmapped `500
        // SERVER_ERROR` carrying the raw exception message instead of the
        // `422 VALIDATION_FAILED` every other write path in this module
        // gives for the same failure class. It never silently reported
        // success on a failed write — the controller's own top-level
        // `Throwable` catch already turns an uncaught exception into a
        // proper (if less specific) error envelope — but the inconsistent
        // status code and unstructured message were worth closing anyway.
        try {
            return $this->doIngest($binary, $meta);
        } catch (ValidationException $exception) {
            throw ApiError::fromValidation($exception);
        }
    }

    /**
     * @param array{filename: string, folder?: string, title?: string,
     *   externalId?: string, conflict?: string, publish?: bool} $meta
     * @return array{record: File, existed: bool}
     * @throws ApiError
     * @throws ValidationException mapped by ingest() above
     */
    protected function doIngest(string $binary, array $meta): array
    {
        $filename = basename(trim((string) ($meta['filename'] ?? '')));
        $folder = trim((string) ($meta['folder'] ?? ''), '/');
        $conflict = (string) ($meta['conflict'] ?? 'overwrite');

        if ($filename === '' || $filename === '.') {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Asset upload requires "filename".');
        }

        if (str_contains($folder, '..')) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Folder paths may not contain "..".');
        }

        if (!in_array($conflict, ['overwrite', 'skip', 'rename'], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Conflict mode "%s" must be overwrite, skip or rename.', $conflict)
            );
        }

        if ($binary === '') {
            throw new ApiError(ErrorCode::ASSET_READ_FAILED, 'Uploaded file is empty.');
        }

        $targetPath = $folder !== '' ? "{$folder}/{$filename}" : $filename;
        $existing = File::get()->filter('FileFilename', $targetPath)->first();

        if ($existing && $conflict === 'skip') {
            // A true no-op, as documented: no metadata rewrite, no publish
            // (#246).
            return ['record' => $existing, 'existed' => true];
        }

        if ($existing && $conflict === 'overwrite' && $existing->getHash() === sha1($binary)) {
            // Identical content — no store write needed, but the record is
            // still returned and metadata still applies.
            return [
                'record' => $this->finalize($existing, $meta, (bool) ($meta['publish'] ?? true)),
                'existed' => true,
            ];
        }

        if ($existing && $conflict === 'overwrite') {
            $existing->setFromString($binary, $targetPath, null, null, [
                'conflict' => AssetStore::CONFLICT_OVERWRITE,
            ]);

            return [
                'record' => $this->finalize($existing, $meta, (bool) ($meta['publish'] ?? true)),
                'existed' => true,
            ];
        }

        // New record (no existing file, or rename mode).
        $class = File::get_class_for_file_extension(
            strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))
        );

        /** @var File $file */
        $file = Injector::inst()->create($class);
        $file->setFromString($binary, $targetPath, null, null, [
            'conflict' => $existing ? AssetStore::CONFLICT_RENAME : AssetStore::CONFLICT_OVERWRITE,
        ]);

        if ($folder !== '') {
            $file->ParentID = Folder::find_or_make($folder)->ID;
        }

        return [
            'record' => $this->finalize($file, $meta, (bool) ($meta['publish'] ?? true)),
            'existed' => false,
        ];
    }

    /**
     * Apply metadata (title, external id), write and optionally publish.
     * A `ValidationException` from `$file->write()` here is mapped by
     * `ingest()`'s own try/catch, same as `setFromString()`'s (see that
     * method's docblock).
     */
    protected function finalize(File $file, array $meta, bool $publish): File
    {
        if (!empty($meta['title'])) {
            $file->Title = (string) $meta['title'];
        }

        if (!empty($meta['externalId']) && $this->externalIds->supports(get_class($file))) {
            $file->setField($this->externalIds->fieldName(), (string) $meta['externalId']);
        }

        if ($file->isChanged() || !$file->isInDB()) {
            $file->write();
        }

        if ($publish) {
            $file->publishSingle();
        }

        return $file;
    }
}
