<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Write\RecordWriter;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Versioned\Versioned;

/**
 * `PATCH records/$ClassRef/$ID`: a field update to one existing record
 * (#232), the narrow write a live site needs without opening the
 * population endpoints. Body `{"fields": {...}, "publish": "none|single"}`
 * and nothing else: no create, no relations, no cascade.
 *
 * It goes through RecordWriter::update(), so it has every check a batch
 * `update` op has: the `update` verb on the record's own class plus
 * canEdit(), the write policy and allowlist, the `action` verb when
 * `publish` is `single`, and the same warnings. It needs
 * CONTENT_API_ACCESS, not CONTENT_API_POPULATE, and is not behind the
 * population gate; `disabled_environments` closes it per environment.
 */
class RecordUpdateHandler
{
    use Configurable;
    use Injectable;

    private const BODY_KEYS = ['fields', 'publish'];

    private const PUBLISH_MODES = ['none', 'single'];

    /**
     * Environment types (`dev`, `test`, `live`) where PATCH is refused.
     * Empty by default; YAML merges arrays additively, so a project can
     * close an environment here but not reopen one the module closes.
     *
     * @var string[]
     */
    private static array $disabled_environments = [];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'reader' => '%$' . RecordsHandler::class,
        'writer' => '%$' . RecordWriter::class,
        'serializer' => '%$' . RecordSerializer::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordsHandler $reader = null;

    public ?RecordWriter $writer = null;

    public ?RecordSerializer $serializer = null;

    /**
     * Whether PATCH is open in the current environment (reported by
     * `GET schema/site` as `recordUpdateEnabled`).
     */
    public static function isEnabled(): bool
    {
        return !in_array(
            Director::get_environment_type(),
            (array) static::config()->get('disabled_environments'),
            true
        );
    }

    /**
     * @throws ApiError
     */
    public function update(HTTPRequest $request, AuthContext $context): array
    {
        if (!static::isEnabled()) {
            $environment = Director::get_environment_type();

            // Not the population gate: SS_CONTENT_API_ALLOW_POPULATE does
            // not open this, the config key named in details does.
            throw new ApiError(
                ErrorCode::ENV_FORBIDDEN,
                sprintf('Single-record updates are disabled in the "%s" environment.', $environment),
                [[
                    'code' => ErrorCode::ENV_FORBIDDEN->value,
                    'environment' => $environment,
                    'config' => 'Dynamic\\ContentApi\\Control\\Handlers\\RecordUpdateHandler.disabled_environments',
                ]]
            );
        }

        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $this->policy->checkClassAccess($className, 'update', $context->member);
        $body = $this->body($request);

        return Versioned::withVersionedMode(function () use ($request, $className, $body, $context) {
            Versioned::set_stage(Versioned::DRAFT);

            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            // update() checks the verb on the record's own class, which may
            // narrow the URL class's (#236), plus canEdit().
            $result = $this->writer->update($record, $body, $context->member);
            $meta = ['operation' => $result['operation']];

            if ($result['warnings'] !== []) {
                $meta['warnings'] = $result['warnings'];
            }

            return [
                'data' => $this->serializer->serialize($result['record']),
                'meta' => $meta,
            ];
        });
    }

    /**
     * @return array{fields: array<string, mixed>, publish: string}
     * @throws ApiError PAYLOAD_INVALID
     */
    protected function body(HTTPRequest $request): array
    {
        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        $unknown = array_diff(array_keys($body), RecordUpdateHandler::BODY_KEYS);

        if ($unknown !== []) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Unsupported key(s) %s: a single-record update takes only "fields" and "publish". '
                        . 'Use POST batch for relations or creates.',
                    implode(', ', array_map(fn ($key) => '"' . $key . '"', $unknown))
                )
            );
        }

        if (!isset($body['fields']) || !is_array($body['fields']) || $body['fields'] === []) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"fields" must be a non-empty object.');
        }

        $publish = $body['publish'] ?? 'none';

        if (!in_array($publish, RecordUpdateHandler::PUBLISH_MODES, true)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"publish" must be "none" or "single".');
        }

        return ['fields' => $body['fields'], 'publish' => $publish];
    }
}
