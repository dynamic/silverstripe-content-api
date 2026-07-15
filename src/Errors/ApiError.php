<?php

namespace Dynamic\ContentApi\Errors;

use Exception;
use SilverStripe\Core\Validation\ValidationException;

/**
 * Throwable carrying a machine-readable error code, HTTP status and optional
 * per-field details. Converted to the JSON error envelope by the controller.
 */
class ApiError extends Exception
{
    /**
     * Maps a caught ValidationException to a VALIDATION_FAILED ApiError using
     * its structured `getResult()->getMessages()` — never the raw
     * `getMessage()` text. Every `$record->write()` call site that catches
     * ValidationException should route through this rather than embedding
     * `$exception->getMessage()` directly: that bypasses the controller's
     * dev/test-only gate on raw exception text (#21) and returns the same
     * shape regardless of environment.
     *
     * `$context` prefixes the top-level message with which record/operation
     * failed (e.g. `Child "widget-1"`) without touching the raw exception
     * text — the per-field detail is what the caller actually needs.
     */
    public static function fromValidation(ValidationException $exception, ?string $context = null): self
    {
        $details = [];

        foreach ($exception->getResult()->getMessages() as $message) {
            $details[] = [
                'field' => ($message['fieldName'] ?? '') !== '' ? $message['fieldName'] : null,
                'code' => 'VALIDATION',
                'message' => (string) ($message['message'] ?? ''),
            ];
        }

        // Don't claim a field count the (possibly empty) $details array
        // can't back up — toArray() omits 'details' entirely when it's
        // empty, so a forced-to-1 count would contradict the response body.
        $summary = $details !== []
            ? sprintf('%d field(s) failed validation.', count($details))
            : 'Validation failed.';

        return new self(
            ErrorCode::VALIDATION_FAILED,
            $context !== null ? sprintf('%s: %s', $context, $summary) : $summary,
            $details
        );
    }

    /**
     * @param array<int, array<string, mixed>> $details per-field/per-item error details
     */
    public function __construct(
        protected ErrorCode $errorCode,
        string $message = '',
        protected array $details = [],
        protected ?int $status = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value);
    }

    public function getErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function getStatus(): int
    {
        return $this->status ?? $this->errorCode->httpStatus();
    }

    /**
     * The error body used in both top-level error envelopes and per-operation
     * batch results.
     */
    public function toArray(): array
    {
        $body = [
            'code' => $this->errorCode->value,
            'status' => $this->getStatus(),
            'message' => $this->getMessage(),
        ];

        if ($this->details !== []) {
            $body['details'] = $this->details;
        }

        return $body;
    }
}
