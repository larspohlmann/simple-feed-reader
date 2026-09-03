<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The flow-binding cookie's whole lifecycle in one place: the set and the clear
 * together, so their six attributes cannot drift apart across two call sites.
 */
final readonly class FlowCookie
{
    /**
     * The cookie that binds a flow to the browser that started it.
     *
     * `__Host-` is a browser-enforced prefix: the cookie is rejected unless it is
     * `Secure`, `Path=/` and carries NO `Domain`. The last is the
     * security-relevant one — with no `Domain`, no other host in the registrable
     * domain can write this cookie into the backend's origin, so a compromised
     * sibling cannot pin the binding to a value it knows.
     *
     * Public because OAuthFlowTest asserts against it: a hard-coded string would
     * keep passing if this were renamed and the cookie silently stopped.
     */
    public const string NAME = '__Host-oauth_flow';

    /**
     * The state's life plus the login code's: the cookie must survive both legs,
     * and a session cookie would outlive them for as long as the browser stayed
     * open.
     *
     * The sum is not padding. A callback may arrive in the final second of the
     * state's ten minutes, and the code it mints then lives a further thirty; a
     * cookie expiring with the state alone would leave that sign-in unable to
     * exchange, failing like a bad code. Computed from the two stores, so
     * changing either lifetime cannot silently reopen that gap.
     */
    private const int LIFETIME_SECONDS = OAuthStateStore::LIFETIME_SECONDS
        + LoginCodeStore::LIFETIME_SECONDS;

    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * The flow-binding cookie, and every attribute is load-bearing.
     *
     * `SameSite=None` is REQUIRED, not a relaxation. Apple returns its callback
     * as a cross-site POST (`response_mode=form_post`), and a `Lax` cookie is not
     * sent on one — so `Lax` here would leave Google signing in perfectly while
     * every Apple sign-in failed with `invalid_state`: a bug that looks stricter
     * and hits only half the users. `None` in turn requires `Secure`.
     *
     * Nothing is lost by `SameSite` not protecting this cookie: it carries no
     * authority. It names no user, grants nothing, and is useful only to whoever
     * also holds the matching unspent `state`.
     *
     * `Secure` on a plain-HTTP deployment means the cookie is never sent and
     * OAuth never completes — the correct failure, and why there is no flag to
     * turn it off. Local `http://localhost:8000` is unaffected: browsers treat
     * `localhost` as trustworthy and accept `Secure` (and `__Host-`) cookies.
     * Verified in Chromium against this exact attribute string; Firefox since 75.
     *
     * `httpOnly` because no script needs to read it, and `raw: false` so the
     * value is URL-encoded — belt and braces for a value already hex.
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
     * Removes the binding once it has nothing left to bind.
     *
     * That is every FAILURE exit from the callback, and the SUCCESS exit from the
     * exchange — not the callback's success exit, which has just issued a login
     * code bound to this value. Leaving the cookie beyond that would be a durable
     * value set by an unauthenticated endpoint for no purpose: the shape of a
     * tracking cookie even when the contents are meaningless.
     *
     * The attributes must match the ones it was set with, or the browser treats
     * this as a different cookie and clears nothing. Hence one issue() and one
     * clearFrom() rather than a second binding at code-issue time: two set-cookie
     * sites are two places for these six attributes to drift apart, silently.
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
