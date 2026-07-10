<?php

namespace Dynamic\ContentApi\Control;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Auth\TokenAuthenticator;
use Dynamic\ContentApi\Control\Handlers\AuthHandler;
use Dynamic\ContentApi\Control\Handlers\PageHandler;
use Dynamic\ContentApi\Control\Handlers\RecordsHandler;
use Dynamic\ContentApi\Control\Handlers\RecordsWriteHandler;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;
use Throwable;

/**
 * Routing hub for the content API. Owns the JSON envelope, authentication
 * gate and ApiError-to-response conversion; all endpoint logic lives in the
 * handler services (Injector-swappable).
 *
 * Envelope: `{ "data": ..., "meta": { ... }, "error": null }`.
 */
class ContentApiController extends Controller
{
    private static bool $cors_enabled = false;

    private static array $url_handlers = [
        'auth/$Action!' => 'handleAuth',
        'POST records/$ClassRef!/$ID!/$RecordAction!' => 'handleRecordAction',
        'GET records/$ClassRef!/$ID!' => 'handleReadOne',
        'PATCH records/$ClassRef!/$ID!' => 'handleUpdate',
        'DELETE records/$ClassRef!/$ID!' => 'handleDelete',
        'POST records/$ClassRef!' => 'handleCreate',
        'GET records/$ClassRef!' => 'handleReadList',
        'POST pages/$ID!/$PageAction!' => 'handlePageAction',
        '' => 'handleIndex',
    ];

    private static array $allowed_actions = [
        'handleAuth',
        'handleReadOne',
        'handleReadList',
        'handleCreate',
        'handleUpdate',
        'handleDelete',
        'handleRecordAction',
        'handlePageAction',
        'handleIndex',
    ];

    private static array $dependencies = [
        'authenticator' => '%$' . TokenAuthenticator::class,
        'authHandler' => '%$' . AuthHandler::class,
        'recordsHandler' => '%$' . RecordsHandler::class,
        'recordsWriteHandler' => '%$' . RecordsWriteHandler::class,
        'pageHandler' => '%$' . PageHandler::class,
    ];

    public ?TokenAuthenticator $authenticator = null;

    public ?AuthHandler $authHandler = null;

    public ?RecordsHandler $recordsHandler = null;

    public ?RecordsWriteHandler $recordsWriteHandler = null;

    public ?PageHandler $pageHandler = null;

    protected ?AuthContext $authContext = null;

    public function handleAuth(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            return $this->authHandler->handle($request, $this);
        });
    }

    public function handleReadOne(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordsHandler->readOne($request, $this->authContext);
        });
    }

    public function handleReadList(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordsHandler->readList($request, $this->authContext);
        });
    }

    public function handleCreate(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordsWriteHandler->create($request, $this->authContext);
        });
    }

    public function handleUpdate(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordsWriteHandler->update($request, $this->authContext);
        });
    }

    public function handleDelete(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordsWriteHandler->delete($request, $this->authContext);
        });
    }

    public function handleRecordAction(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordsWriteHandler->recordAction($request, $this->authContext);
        });
    }

    public function handlePageAction(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->pageHandler->handle($request, $this->authContext);
        });
    }

    public function handleIndex(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () {
            return [
                'data' => [
                    'name' => 'silverstripe-content-api',
                    'version' => 'v1',
                ],
            ];
        });
    }

    public function getAuthContext(): ?AuthContext
    {
        return $this->authContext;
    }

    /**
     * Authenticate the request and scope the current user to it. No session
     * or IdentityStore involvement — Security::setCurrentUser() only.
     *
     * @throws ApiError
     */
    protected function requireAuth(HTTPRequest $request): AuthContext
    {
        if (!$this->authContext) {
            $this->authContext = $this->authenticator->authenticate($request);
            Security::setCurrentUser($this->authContext->member);
        }

        return $this->authContext;
    }

    /**
     * Run an endpoint callable and wrap its result in the JSON envelope.
     * The callable returns ['data' => ..., 'meta' => [...], 'status' => int].
     */
    protected function withEnvelope(callable $endpoint): HTTPResponse
    {
        try {
            $result = $endpoint();

            return $this->jsonResponse(
                $result['data'] ?? null,
                $result['meta'] ?? [],
                $result['status'] ?? 200
            );
        } catch (ApiError $error) {
            return $this->errorResponse($error);
        } catch (Throwable $exception) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'Content API server error: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            // Surface the real error in dev so agents can self-diagnose;
            // production callers get an opaque message.
            $message = Director::isDev() || Director::isTest()
                ? sprintf('%s: %s', get_class($exception), $exception->getMessage())
                : 'Internal server error.';

            return $this->errorResponse(new ApiError(ErrorCode::SERVER_ERROR, $message));
        }
    }

    protected function jsonResponse(mixed $data, array $meta = [], int $status = 200): HTTPResponse
    {
        $body = [
            'data' => $data,
            'meta' => (object) $meta,
            'error' => null,
        ];

        return HTTPResponse::create(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $status)
            ->addHeader('Content-Type', 'application/json');
    }

    protected function errorResponse(ApiError $error): HTTPResponse
    {
        $body = [
            'data' => null,
            'meta' => (object) [],
            'error' => $error->toArray(),
        ];

        return HTTPResponse::create(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $error->getStatus())
            ->addHeader('Content-Type', 'application/json');
    }
}
