<?php

namespace Dynamic\ContentApi\Control;

use Colymba\RESTfulAPI\Authenticators\TokenAuthenticator as ColymbaTokenAuthenticator;
use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Control\Handlers\AssetHandler;
use Dynamic\ContentApi\Control\Handlers\AuthHandler;
use Dynamic\ContentApi\Control\Handlers\BatchHandler;
use Dynamic\ContentApi\Control\Handlers\CompositionHandler;
use Dynamic\ContentApi\Control\Handlers\FingerprintHandler;
use Dynamic\ContentApi\Control\Handlers\PageHandler;
use Dynamic\ContentApi\Control\Handlers\ParityHandler;
use Dynamic\ContentApi\Control\Handlers\RecordActionsHandler;
use Dynamic\ContentApi\Control\Handlers\RecordsHandler;
use Dynamic\ContentApi\Control\Handlers\SchemaHandler;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Logging\RequestLogger;
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
    private static array $url_handlers = [
        'GET auth/session' => 'handleAuthSession',
        'POST records/$ClassRef!/$ID!/$RecordAction!' => 'handleRecordAction',
        // Must sit above the plain single-record GET below — SilverStripe
        // matches $url_handlers top-down, and "$ClassRef!/$ID!" alone would
        // otherwise swallow ".../$ID/parity" first (#120).
        'GET records/$ClassRef!/$ID!/parity' => 'handleRecordParity',
        'GET records/$ClassRef!/$ID!' => 'handleReadOne',
        'GET records/$ClassRef!' => 'handleReadList',
        'POST pages/$ID!/$PageAction!' => 'handlePageAction',
        'POST assets' => 'handleAssetUpload',
        'GET assets/$ID!' => 'handleAssetRead',
        'POST batch' => 'handleBatch',
        'POST compositions/page' => 'handleComposition',
        'GET schema/$ClassRef' => 'handleSchema',
        'GET schema' => 'handleSchema',
        'GET fingerprint' => 'handleFingerprint',
        '' => 'handleIndex',
    ];

    private static array $allowed_actions = [
        'handleAuthSession',
        'handleReadOne',
        'handleReadList',
        'handleRecordAction',
        'handleRecordParity',
        'handlePageAction',
        'handleAssetUpload',
        'handleAssetRead',
        'handleBatch',
        'handleComposition',
        'handleSchema',
        'handleFingerprint',
        'handleIndex',
    ];

    private static array $dependencies = [
        'authenticator' => '%$' . ColymbaTokenAuthenticator::class,
        'authHandler' => '%$' . AuthHandler::class,
        'recordsHandler' => '%$' . RecordsHandler::class,
        'recordActionsHandler' => '%$' . RecordActionsHandler::class,
        'parityHandler' => '%$' . ParityHandler::class,
        'pageHandler' => '%$' . PageHandler::class,
        'assetHandler' => '%$' . AssetHandler::class,
        'batchHandler' => '%$' . BatchHandler::class,
        'compositionHandler' => '%$' . CompositionHandler::class,
        'schemaHandler' => '%$' . SchemaHandler::class,
        'fingerprintHandler' => '%$' . FingerprintHandler::class,
        'requestLogger' => '%$' . RequestLogger::class,
    ];

    public ?ColymbaTokenAuthenticator $authenticator = null;

    public ?AuthHandler $authHandler = null;

    public ?RecordsHandler $recordsHandler = null;

    public ?RecordActionsHandler $recordActionsHandler = null;

    public ?ParityHandler $parityHandler = null;

    public ?PageHandler $pageHandler = null;

    public ?AssetHandler $assetHandler = null;

    public ?BatchHandler $batchHandler = null;

    public ?CompositionHandler $compositionHandler = null;

    public ?SchemaHandler $schemaHandler = null;

    public ?FingerprintHandler $fingerprintHandler = null;

    public ?RequestLogger $requestLogger = null;

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

    public function handleRecordParity(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->parityHandler->parity($request, $this->authContext);
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

    public function handleFingerprint(HTTPRequest $request): HTTPResponse
    {
        return $this->withEnvelope(function () use ($request) {
            $this->requireAuth($request);

            return $this->fingerprintHandler->handle($request, $this->authContext);
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

        // Read the expiry from colymba's configured column (it derives the
        // name from TokenAuthExtension's $db spec), not a hardcoded field —
        // a renamed column must not make every token read as expired.
        $expires = (int) $member->getField($this->expiryColumn());

        if ($expires <= time()) {
            throw new ApiError(ErrorCode::TOKEN_EXPIRED, 'Token expired.');
        }

        Security::setCurrentUser($member);

        return $this->authContext = new AuthContext($member, $expires);
    }

    /**
     * colymba's expiry column name, derived from TokenAuthExtension's $db
     * spec exactly as its TokenAuthenticator does (falls back to the default).
     */
    protected function expiryColumn(): string
    {
        $db = (array) Config::inst()->get(
            'Colymba\\RESTfulAPI\\Extensions\\TokenAuthExtension',
            'db'
        );

        return (string) (array_search('Int', $db, true) ?: 'ApiTokenExpire');
    }

    /**
     * Run an endpoint callable and wrap its result in the JSON envelope.
     * The callable returns ['data' => ..., 'meta' => [...], 'status' => int].
     *
     * Buffers all output the callable produces: a PHP diagnostic that isn't
     * a `Throwable` — most notably a deprecation notice from application
     * code this module doesn't control (e.g. a third-party DataObject's
     * `onBeforeWrite()`) — never reaches `withEnvelope()`'s own try/catch,
     * because it doesn't throw. In dev/test environments SilverStripe's
     * default error handler `echo`s an HTML debug block directly to output
     * for exactly this case, bypassing this controller's `HTTPResponse`
     * entirely. Left unbuffered, that HTML gets sent ahead of (and
     * concatenated with) this method's own well-formed JSON body — the
     * client receives a response that's neither valid JSON nor an accurate
     * reflection of the real outcome, even though the underlying operation
     * may have completed exactly as intended (confirmed empirically: #70).
     * Any captured stray output is logged, not silently discarded, so the
     * underlying diagnostic is still visible to whoever reads the logs.
     *
     * Drains down to the buffer level this method itself opened, not just
     * one `ob_get_clean()` call — if application code inside `$endpoint()`
     * opens its own unbalanced output buffer (starts one and never closes
     * it), a single `ob_get_clean()` would pop that orphaned buffer
     * instead, leaving this method's own buffer open to flush unbuffered
     * at request shutdown: #70's exact symptom, reintroduced by the very
     * code meant to fix it. Known gap this can't close: SilverStripe's own
     * `Deprecation::notice()` defers its output via
     * `register_shutdown_function()` (see framework source) specifically
     * so it fires after headers/response are already sent — that output
     * happens after this method has already returned and can't be
     * buffered here at all. Same for a true PHP fatal (not a `Throwable`):
     * execution never returns to drain the buffer, and PHP's shutdown
     * sequence flushes whatever was buffered raw and unlabeled. Both are
     * pre-existing limitations, not introduced by this method.
     */
    protected function withEnvelope(callable $endpoint): HTTPResponse
    {
        $bufferLevel = ob_get_level();
        $startTime = microtime(true);
        $result = null;
        $errorCode = null;
        ob_start();

        try {
            $result = $endpoint();

            $response = $this->jsonResponse(
                $result['data'] ?? null,
                $result['meta'] ?? [],
                $result['status'] ?? 200
            );
        } catch (ApiError $error) {
            $errorCode = $error->getErrorCode();
            $response = $this->errorResponse($error);
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

            $errorCode = ErrorCode::SERVER_ERROR;
            $response = $this->errorResponse(new ApiError(ErrorCode::SERVER_ERROR, $message));
        } finally {
            // If application code inside $endpoint() closed this method's
            // own buffer itself (an unbalanced ob_end_clean()/
            // ob_end_flush()/ob_get_clean() call), whatever it contained is
            // already gone by the time we regain control — flushed
            // straight to the real output stream if it was ob_end_flush(),
            // discarded if it was ob_end_clean(). Nothing here can recover
            // that content, but the imbalance itself is worth its own
            // distinct warning rather than reading as a silent "nothing to
            // report". This check must run before the drain loop below —
            // once confirmed balanced, that loop safely drains everything
            // still open above our own level, including any additional
            // buffer $endpoint() opened and left open (a well-intentioned
            // library that ob_start()s and forgets to close).
            $imbalanced = ob_get_level() <= $bufferLevel;
            $strayOutput = '';

            if (!$imbalanced) {
                while (ob_get_level() > $bufferLevel) {
                    $strayOutput = ob_get_clean() . $strayOutput;
                }
            }
        }

        if ($imbalanced) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Content API endpoint left an output buffer imbalanced (application code '
                    . 'closed a buffer level this controller never opened) — some stray output '
                    . 'may have already reached the raw response uncaught. Find and fix the '
                    . 'unbalanced ob_end_clean()/ob_end_flush()/ob_get_clean() call.'
            );
        } elseif (trim($strayOutput) !== '') {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Content API endpoint produced stray output outside the JSON envelope '
                    . '(suppressed from the response; likely a PHP notice/deprecation from '
                    . 'application code) — the response itself is still accurate, but check '
                    . 'the source below for a bug worth fixing.',
                ['strayOutput' => substr($strayOutput, 0, 4000)]
            );
        }

        // #207: null-safe — a null requestLogger is unreachable in practice
        // (Injector::create() always resolves $dependencies), but this
        // point is reached after withEnvelope()'s own try/catch/finally has
        // already closed, so nothing here may throw and discard an
        // already-built response. RequestLogger::log() derives every
        // logged field itself (including the `opFailures` count a
        // partially-failed `POST batch` needs despite its HTTP 200 — see
        // BatchProcessor::run()'s $summary) and wraps its own body in a
        // try/catch for exactly this reason.
        $this->requestLogger?->log(
            $this->getRequest(),
            $response,
            $this->authContext?->member,
            $this->getAction(),
            $errorCode,
            $startTime,
            $result
        );

        return $response;
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
