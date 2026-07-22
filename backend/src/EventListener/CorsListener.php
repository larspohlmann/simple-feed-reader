<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets the SPA's origin call this API, WITH cookies.
 *
 * The credentialed part is the reason this exists rather than being a
 * convenience. `POST /api/auth/oauth/exchange` needs the `__Host-oauth_flow`
 * cookie to prove the browser exchanging a login code is the one that earned it
 * (see OAuthController and LoginCodeStore). The SPA sends that with
 * `credentials: 'include'`, and a browser only attaches cookies to a
 * cross-origin XHR when the response says `Access-Control-Allow-Credentials:
 * true` — and refuses outright if the allowed origin is `*`.
 *
 * So the allowed origin is a single exact string derived from
 * APP_FRONTEND_URL, and the response echoes THAT string rather than the
 * request's `Origin` header. The two are equal whenever anything is emitted at
 * all, so the distinction looks pedantic; it is not. Reflecting the request's
 * own origin is how a CORS config becomes "allow anybody with credentials", and
 * a config that cannot express the wrong thing is worth more than a comment
 * asking the next reader not to.
 *
 * ## Why a listener and not nelmio/cors-bundle
 *
 * The bundle is the standard answer and would be the right one for an API with
 * several client origins, per-path rules or a wildcard-pattern policy. This one
 * has exactly one allowed origin, known at deploy time from an env var that
 * already exists. Against that, the bundle brings a config surface whose
 * headline options are `allow_origin: ['*']` and regex patterns — both of which
 * are precisely the mistakes that matter here, both a one-line edit away, and
 * neither caught by any test. Forty lines with no wildcard to reach for is the
 * smaller attack surface and the smaller dependency, on a showcase repo where
 * the reader is meant to be able to see the whole policy at once.
 *
 * Revisit this the day a second origin is legitimate. The bundle is a better
 * answer to that problem than a list bolted onto this one.
 *
 * ## Same-origin deployments
 *
 * The likely production shape is the SPA and the API on ONE origin, where the
 * browser applies no CORS at all. Nothing here breaks that: a same-origin GET
 * sends no `Origin` header and gets no headers back, and a same-origin POST
 * does send one, matches, and gets headers the browser then ignores. The policy
 * is inert rather than load-bearing there, which is the correct behaviour — a
 * CORS config that only worked cross-origin would be its own bug.
 *
 * Note also that cross-ORIGIN is not cross-SITE. Local development runs the SPA
 * on `http://localhost:4200` and the API on `http://localhost:8000`: different
 * origins, so this listener is required, but the same site, because ports play
 * no part in a site. The flow cookie's `SameSite=None` is therefore not what
 * makes local development work — it is there for Apple's cross-site callback
 * POST — and local development would work without it.
 */
final class CorsListener
{
    /**
     * Everything the SPA is allowed to preflight. Deliberately a fixed list of
     * what this API actually answers, not a reflection of whatever the browser
     * asked for.
     */
    private const ALLOWED_METHODS = 'GET, POST, PATCH, DELETE, OPTIONS';

    /**
     * `Authorization` for the JWT, `Content-Type` for the JSON bodies. Nothing
     * else is read from a request header by any endpoint.
     */
    private const ALLOWED_HEADERS = 'Authorization, Content-Type';

    /** Ten minutes of preflight caching, Chromium's ceiling for this header. */
    private const MAX_AGE = '600';

    /**
     * The one origin this API answers to, as scheme://host[:port], or null if
     * APP_FRONTEND_URL is not a parseable absolute URL.
     *
     * Null means no CORS headers are ever emitted — the closed failure. A
     * misconfigured frontend URL must break the SPA loudly, not silently widen
     * who may call this API with cookies attached.
     */
    private readonly ?string $allowedOrigin;

    public function __construct(
        #[Autowire('%env(APP_FRONTEND_URL)%')] string $frontendUrl,
    ) {
        $this->allowedOrigin = self::originOf($frontendUrl);
    }

    /**
     * Answers the preflight before routing or the firewall sees it.
     *
     * Priority 250 puts this above RouterListener (32) and the firewall (8),
     * which matters: a preflight `OPTIONS` carries no cookie and no
     * `Authorization` header by specification, so left to run it would draw a
     * 405 from the router or a 401 from the firewall — and a browser reads
     * either as "preflight failed" and never sends the real request.
     *
     * A preflight from an origin we do not allow is deliberately NOT
     * short-circuited. It falls through and gets whatever the router would have
     * said, so this listener never turns an unknown URL into a 204; the browser
     * blocks the real request either way, on the absent headers.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !self::isPreflight($request)) {
            return;
        }

        if (null === $this->allowedOrigin || !$this->isAllowed($request)) {
            return;
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Max-Age', self::MAX_AGE);

        $this->allow($response);

        $event->setResponse($response);
    }

    /**
     * Puts the credentialed headers on the real response.
     *
     * `Vary: Origin` is appended whether or not anything else is added, and is
     * not optional. This response differs by request origin, so a shared cache
     * that ignored that could hand an allowed origin a copy stored for a
     * disallowed one — breaking every credentialed call for as long as the
     * entry lived — or the reverse.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Vary', 'Origin', replace: false);

        if (null === $this->allowedOrigin || !$this->isAllowed($event->getRequest())) {
            return;
        }

        $this->allow($response);
    }

    /**
     * The two headers that make a cross-origin call credentialed.
     *
     * The origin written out is the CONFIGURED one, never the request's. See
     * the class docblock.
     */
    private function allow(Response $response): void
    {
        \assert(null !== $this->allowedOrigin);

        $response->headers->set('Access-Control-Allow-Origin', $this->allowedOrigin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * hash_equals rather than `===` for no timing reason — an origin is public.
     * It is here because it is total: it cannot be tricked by a prefix, a case
     * difference or a trailing slash the way a `str_starts_with` or a
     * normalising comparison could, and origin comparison is exactly where
     * those shortcuts turn into `evil-example.com.attacker.net`.
     */
    private function isAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return null !== $this->allowedOrigin
            && \is_string($origin)
            && hash_equals($this->allowedOrigin, $origin);
    }

    /**
     * A preflight is an `OPTIONS` carrying `Access-Control-Request-Method`. The
     * header is what distinguishes it from a plain `OPTIONS`, which is a
     * request about the resource and not about CORS.
     */
    private static function isPreflight(Request $request): bool
    {
        return Request::METHOD_OPTIONS === $request->getMethod()
            && $request->headers->has('Access-Control-Request-Method');
    }

    /**
     * Reduces a configured URL to the origin a browser would send: scheme, host
     * and non-default port, with any path, query or fragment dropped. An
     * `Origin` header never carries those, so comparing against the raw
     * APP_FRONTEND_URL would silently never match on a value with a trailing
     * slash — the most likely way for an operator to write it.
     */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
}
