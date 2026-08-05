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
    case ROLLBACK_UNVERIFIED = 'ROLLBACK_UNVERIFIED';

    public function httpStatus(): int
    {
        return match ($this) {
            ErrorCode::UNAUTHENTICATED, ErrorCode::TOKEN_EXPIRED => 401,
            ErrorCode::FORBIDDEN, ErrorCode::FORBIDDEN_CLASS, ErrorCode::FORBIDDEN_RECORD,
            ErrorCode::ENV_FORBIDDEN, ErrorCode::HOMEPAGE_CONVERSION_FORBIDDEN => 403,
            ErrorCode::UNKNOWN_CLASS, ErrorCode::NOT_FOUND => 404,
            ErrorCode::METHOD_NOT_ALLOWED => 405,
            ErrorCode::MULTIPLE_MATCHES, ErrorCode::ALREADY_EXISTS, ErrorCode::ASSET_CONFLICT,
            ErrorCode::URLSEGMENT_COLLISION => 409,
            ErrorCode::VALIDATION_FAILED, ErrorCode::UNKNOWN_FIELD, ErrorCode::READONLY_FIELD,
            ErrorCode::UNKNOWN_RELATION, ErrorCode::UNRESOLVED_REF, ErrorCode::CIRCULAR_REF,
            ErrorCode::EXTERNAL_ID_UNSUPPORTED, ErrorCode::TOKEN_RESOLUTION_FAILED => 422,
            ErrorCode::PAYLOAD_INVALID => 400,
            ErrorCode::ASSET_READ_FAILED => 502,
            ErrorCode::FEATURE_UNAVAILABLE => 501,
            ErrorCode::SERVER_ERROR, ErrorCode::ROLLBACK_UNVERIFIED => 500,
        };
    }
}
