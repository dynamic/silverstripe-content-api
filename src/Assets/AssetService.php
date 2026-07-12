<?php

namespace Dynamic\ContentApi\Assets;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

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
 */
class AssetService
{
    use Injectable;

    private static array $dependencies = [
        'externalIds' => '%$' . ExternalIdResolver::class,
    ];

    public ?ExternalIdResolver $externalIds = null;

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
        $folder = trim((string) ($meta['folder'] ?? ''), '/');
        $conflict = (string) ($meta['conflict'] ?? 'overwrite');

        if ($filename === '' || $filename === '.') {
            return File::class;
        }

        $targetPath = $folder !== '' ? "{$folder}/{$filename}" : $filename;
        $existing = File::get()->filter('FileFilename', $targetPath)->first();

        // `rename` never reuses the existing record — it writes a new record of
        // the extension-derived class — so only skip/overwrite adopt its class.
        if ($existing && $conflict !== 'rename') {
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
            return ['record' => $this->finalize($existing, $meta, false), 'existed' => true];
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
