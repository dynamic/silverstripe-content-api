<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Schema\SchemaService;
use Dynamic\ContentApi\Security\ContentApiPermissions;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Permission;

/**
 * Introspection endpoints: `GET schema` / `GET schema/site` (site index) and
 * `GET schema/$ClassRef` (payload contract per class). Requires
 * CONTENT_API_ACCESS or the standalone CONTENT_API_SCHEMA permission.
 */
class SchemaHandler
{
    use Injectable;

    private static array $dependencies = [
        'schema' => '%$' . SchemaService::class,
    ];

    public ?SchemaService $schema = null;

    public function handle(HTTPRequest $request, AuthContext $context): array
    {
        $held = Permission::checkMember($context->member, ContentApiPermissions::ACCESS)
            || Permission::checkMember($context->member, ContentApiPermissions::SCHEMA);

        if (!$held) {
            throw new ApiError(
                ErrorCode::FORBIDDEN,
                'Member does not have content API schema access.'
            );
        }

        $classRef = (string) $request->param('ClassRef');

        if ($classRef === '' || $classRef === 'site') {
            return ['data' => $this->schema->siteSchema()];
        }

        return ['data' => $this->schema->classSchema($classRef)];
    }
}
