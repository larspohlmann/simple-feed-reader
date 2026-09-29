<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Url\Support\UrlOrigin;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Allows the one configured frontend origin, with credentials, for the OAuth exchange's flow cookie. It echoes the
 * configured string, never the request's Origin: reflecting that would allow any origin with credentials.
 * Why a listener and not a bundle: docs/oauth-sign-in.md#why-a-hand-written-listener
 */
final readonly class CorsListener
{
    /**
     * Everything the SPA is allowed to preflight. Deliberately a fixed list of
     * what this API actually answers, not a reflection of whatever the browser
     * asked for.
     */
    private const string ALLOWED_METHODS = 'GET, POST, PATCH, DELETE, OPTIONS';

    /**
     * `Authorization` for the JWT, `Content-Type` for the JSON bodies. Nothing
     * else is read from a request header by any endpoint.
     */
    private const string ALLOWED_HEADERS = 'Authorization, Content-Type';

    /** Ten minutes of preflight caching, Chromium's ceiling for this header. */
    private const string MAX_AGE = '600';

    /** scheme://host[:port] of APP_FRONTEND_URL, or null when it does not parse: then no CORS header is ever sent. */
    private ?string $allowedOrigin;

    public function __construct(
        #[Autowire('%env(APP_FRONTEND_URL)%')] string $frontendUrl,
    ) {
        // An Origin header carries no path, so the raw APP_FRONTEND_URL would never match one with a trailing slash.
        $this->allowedOrigin = UrlOrigin::of($frontendUrl);
    }

    /**
     * Answers an allowed preflight above RouterListener (32) and the firewall (8), which would 405 or 401 it. A
     * disallowed origin falls through, so this never turns an unknown URL into a 204.
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

    /** Always varies by Origin, allowed or not: a shared cache must not hand one origin another's answer. */
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

    /** Exact comparison: a prefix or normalising match is how `example.com.attacker.net` gets in. */
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
}
