<?php

namespace Dynamic\ContentApi\Errors;

use Exception;

/**
 * Throwable carrying a machine-readable error code, HTTP status and optional
 * per-field details. Converted to the JSON error envelope by the controller.
 */
class ApiError extends Exception
{
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
