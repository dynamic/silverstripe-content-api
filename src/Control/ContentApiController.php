<?php

namespace Dynamic\ContentApi\Control;

use Colymba\RESTfulAPI\Authenticators\TokenAuthenticator as ColymbaTokenAuthenticator;
use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Control\Handlers\AssetHandler;
use Dynamic\ContentApi\Control\Handlers\AuthHandler;
use Dynamic\ContentApi\Control\Handlers\BatchHandler;
use Dynamic\ContentApi\Control\Handlers\CompositionHandler;
use Dynamic\ContentApi\Control\Handlers\PageHandler;
use Dynamic\ContentApi\Control\Handlers\RecordActionsHandler;
use Dynamic\ContentApi\Control\Handlers\RecordsHandler;
use Dynamic\ContentApi\Control\Handlers\SchemaHandler;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Throwable;

/**
 * Routing hub for the content API. Owns the JSON envelope, authentication
 * gate and ApiError-to-response conversion; all endpoint logic lives in the
 * handler services (Injector-swappable).
 *
 * Authentication is colymba/silverstripe-restfulapi's TokenAuthenticator —
 * the same token works on this surface and on colymba's generic `/api` CRUD.
 * Generic single-record writes live there; this controller provides what
 * colymba lacks: stage-aware reads, stage actions, batch, compositions,
 * assets, schema introspection.
 *
 * Envelope: `{ "data": ..., "meta": { ... }, "error": null }`.
 */
class ContentApiController extends Controller
{
    private static bool $cors_enabled = false;

    private static array $url_handlers = [
        'GET auth/session' => 'handleAuthSession',
        'POST records/$ClassRef!/$ID!/$RecordAction!' => 'handleRecordAction',
        'GET records/$ClassRef!/$ID!' => 'handleReadOne',
        'GET records/$ClassRef!' => 'handleReadList',
        'POST pages/$ID!/$PageAction!' => 'handlePageAction',
        'POST assets' => 'handleAssetUpload',
        'GET assets/$ID!' => 'handleAssetRead',
        'POST batch' => 'handleBatch',
        'POST compositions/page' => 'handleComposition',
        'GET schema/$ClassRef' => 'handleSchema',
        'GET schema' => 'handleSchema',
        '' => 'handleIndex',
    ];

    private static array $allowed_actions = [
        'handleAuthSession',
        'handleReadOne',
        'handleReadList',
        'handleRecordAction',
        'handlePageAction',
        'handleAssetUpload',
        'handleAssetRead',
        'handleBatch',
        'handleComposition',
        'handleSchema',
        'handleIndex',
    ];

    private static array $dependencies = [
        'authenticator' => '%$' . ColymbaTokenAuthenticator::class,
        'authHandler' => '%$' . AuthHandler::class,
        'recordsHandler' => '%$' . RecordsHandler::class,
        'recordActionsHandler' => '%$' . RecordActionsHandler::class,
        'pageHandler' => '%$' . PageHandler::class,
        'assetHandler' => '%$' . AssetHandler::class,
        'batchHandler' => '%$' . BatchHandler::class,
        'compositionHandler' => '%$' . CompositionHandler::class,
        'schemaHandler' => '%$' . SchemaHandler::class,
    ];

    public ?ColymbaTokenAuthenticator $authenticator = null;

    public ?AuthHandler $authHandler = null;

    public ?RecordsHandler $recordsHandler = null;

    public ?RecordActionsHandler $recordActionsHandler = null;

    public ?PageHandler $pageHandler = null;

    public ?AssetHandler $assetHandler = null;

    public ?BatchHandler $batchHandler = null;

    public ?CompositionHandler $compositionHandler = null;

    public ?SchemaHandler $schemaHandler = null;

    protected ?AuthContext $authContext = null;

    public function handleAuthSession(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->authHandler->session($request, $this->authContext);
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

    public function handleRecordAction(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->recordActionsHandler->recordAction($request, $this->authContext);
        });
    }

    public function handlePageAction(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->pageHandler->handle($request, $this->authContext);
        });
    }

    public function handleAssetUpload(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->assetHandler->upload($request, $this->authContext);
        });
    }

    public function handleAssetRead(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->assetHandler->read($request, $this->authContext);
        });
    }

    public function handleBatch(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->batchHandler->handle($request, $this->authContext);
        });
    }

    public function handleComposition(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->compositionHandler->composePage($request, $this->authContext);
        });
    }

    public function handleSchema(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->schemaHandler->handle($request, $this->authContext);
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
     * Authenticate against colymba's token store, but WITHOUT the security
     * side effects of its authenticate() path. We deliberately do not call
     * authenticate() here, because it (a) logs the member into the CMS
     * session IdentityStore on every request and (b) accepts a token up to a
     * full tokenLife past its advertised expiry. Instead:
     *
     * - header-only: colymba's `?token=` query-var fallback is not honoured on
     *   this surface — tokens in URLs leak into logs, and combined with the
     *   session login would mint a CMS session from a shared link;
     * - getOwner() resolves the member by token with NO session login — the
     *   API stays stateless (Security::setCurrentUser only);
     * - strict expiry: rejected at the advertised ApiTokenExpire, not
     *   colymba's `now - tokenLife` grace window.
     *
     * colymba still owns token storage, minting (resetToken/getToken), the
     * api/auth/* endpoints, generic CRUD, the serializer and ACL.
     *
     * @throws ApiError UNAUTHENTICATED | TOKEN_EXPIRED
     */
    protected function requireAuth(HTTPRequest $request): AuthContext
    {
        if ($this->authContext) {
            return $this->authContext;
        }

        $headerName = (string) Config::inst()->get(ColymbaTokenAuthenticator::class, 'tokenHeader');

        if (!$request->getHeader($headerName)) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED, 'No API token provided.');
        }

        // Header is present, so getOwner() resolves from it (never the query
        // var) and performs no IdentityStore login.
        $member = $this->authenticator->getOwner($request);

        if (!$member instanceof Member) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED, 'Token invalid.');
        }

        $expires = (int) $member->ApiTokenExpire;

        if ($expires <= time()) {
            throw new ApiError(ErrorCode::TOKEN_EXPIRED, 'Token expired.');
        }

        Security::setCurrentUser($member);

        return $this->authContext = new AuthContext($member, $expires);
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
