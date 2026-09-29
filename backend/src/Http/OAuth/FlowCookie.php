<?php

declare(strict_types=1);

namespace App\Http\OAuth;

use App\Service\OAuth\LoginCodeStore;
use App\Service\OAuth\OAuthStateStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The flow-binding cookie's whole lifecycle in one place: the set and the clear
 * together, so their six attributes cannot drift apart across two call sites.
 */
final readonly class FlowCookie
{
    /** `__Host-`: browsers refuse it unless Secure, Path=/ and no Domain, so no sibling host can plant a binding. */
    public const string NAME = '__Host-oauth_flow';

    /**
     * The state's life plus the login code's: a callback in the state's last second mints a code whose exchange
     * must still find this cookie.
     */
    private const int LIFETIME_SECONDS = OAuthStateStore::LIFETIME_SECONDS
        + LoginCodeStore::LIFETIME_SECONDS;

    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * `SameSite=None` is required, not a relaxation: Apple's callback is a cross-site form_post, which never carries a
     * `Lax` cookie. Why each attribute holds: docs/oauth-sign-in.md#the-flow-cookie
     */
    public function issue(string $browserToken): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($browserToken)
            ->withExpires($this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS)
            ->withPath('/')
            ->withDomain(null)
            ->withSameSite(Cookie::SAMESITE_NONE);
    }

    /**
     * Called on every failed callback and on a successful exchange, never on a successful callback: the login code it
     * issued is bound to this cookie. The attributes must match issue()'s, or the browser clears nothing.
     *
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    public function clearFrom(Response $response): Response
    {
        $response->headers->clearCookie(
            self::NAME,
            '/',
            null,
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_NONE,
        );

        return $response;
    }
}
