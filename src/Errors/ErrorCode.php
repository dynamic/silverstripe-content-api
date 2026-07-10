<?php

namespace Dynamic\ContentApi\Errors;

/**
 * Machine-readable error codes shared by every endpoint (and by future MCP tool
 * definitions). Each code maps to a default HTTP status.
 */
enum ErrorCode: string
{
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    case FORBIDDEN = 'FORBIDDEN';
    case FORBIDDEN_CLASS = 'FORBIDDEN_CLASS';
    case FORBIDDEN_RECORD = 'FORBIDDEN_RECORD';
    case ENV_FORBIDDEN = 'ENV_FORBIDDEN';
    case UNKNOWN_CLASS = 'UNKNOWN_CLASS';
    case NOT_FOUND = 'NOT_FOUND';
    case MULTIPLE_MATCHES = 'MULTIPLE_MATCHES';
    case ALREADY_EXISTS = 'ALREADY_EXISTS';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case UNKNOWN_FIELD = 'UNKNOWN_FIELD';
    case READONLY_FIELD = 'READONLY_FIELD';
    case UNKNOWN_RELATION = 'UNKNOWN_RELATION';
    case UNRESOLVED_REF = 'UNRESOLVED_REF';
    case CIRCULAR_REF = 'CIRCULAR_REF';
    case PAYLOAD_INVALID = 'PAYLOAD_INVALID';
    case URLSEGMENT_COLLISION = 'URLSEGMENT_COLLISION';
    case HOMEPAGE_CONVERSION_FORBIDDEN = 'HOMEPAGE_CONVERSION_FORBIDDEN';
    case ASSET_CONFLICT = 'ASSET_CONFLICT';
    case ASSET_READ_FAILED = 'ASSET_READ_FAILED';
    case TOKEN_RESOLUTION_FAILED = 'TOKEN_RESOLUTION_FAILED';
    case EXTERNAL_ID_UNSUPPORTED = 'EXTERNAL_ID_UNSUPPORTED';
    case FEATURE_UNAVAILABLE = 'FEATURE_UNAVAILABLE';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case SERVER_ERROR = 'SERVER_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::UNAUTHENTICATED, self::TOKEN_EXPIRED => 401,
            self::FORBIDDEN, self::FORBIDDEN_CLASS, self::FORBIDDEN_RECORD,
            self::ENV_FORBIDDEN, self::HOMEPAGE_CONVERSION_FORBIDDEN => 403,
            self::UNKNOWN_CLASS, self::NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::MULTIPLE_MATCHES, self::ALREADY_EXISTS, self::ASSET_CONFLICT,
            self::URLSEGMENT_COLLISION => 409,
            self::VALIDATION_FAILED, self::UNKNOWN_FIELD, self::READONLY_FIELD,
            self::UNKNOWN_RELATION, self::UNRESOLVED_REF, self::CIRCULAR_REF,
            self::EXTERNAL_ID_UNSUPPORTED, self::TOKEN_RESOLUTION_FAILED => 422,
            self::PAYLOAD_INVALID => 400,
            self::ASSET_READ_FAILED => 502,
            self::FEATURE_UNAVAILABLE => 501,
            self::SERVER_ERROR => 500,
        };
    }
}
