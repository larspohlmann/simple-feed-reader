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
     * `__Host-` is a browser-enforced prefix: a browser rejects the cookie
     * outright unless it is `Secure`, `Path=/` and carries NO `Domain`
     * attribute. The last of those is the security-relevant one here — with no
     * `Domain`, no other host in the registrable domain can write this cookie
     * into the backend's origin, so a compromised sibling cannot pin the
     * binding to a value it knows.
     *
     * Public because OAuthFlowTest asserts against it. A test that hard-coded
     * the string would keep passing if this were renamed and the cookie
     * silently stopped being set.
     */
    public const string NAME = '__Host-oauth_flow';

    /**
     * The state's life plus the login code's, because the cookie now has to
     * survive both legs — and a session cookie would outlive them for as long
     * as the browser stayed open.
     *
     * The sum is not padding. A callback may legitimately arrive in the final
     * second of the state's ten minutes, and the code it mints then lives a
     * further thirty; a cookie expiring with the state alone would leave that
     * sign-in unable to exchange, failing with what looks exactly like a bad
     * code. Computed from the two stores rather than written as a number, so
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
     * as a cross-site POST (`response_mode=form_post`), and a `Lax` cookie is
     * not sent on a cross-site POST — so `Lax` here would leave Google signing
     * in perfectly while every Apple sign-in failed with `invalid_state`. That
     * is the worst kind of bug to diagnose, because the code looks stricter and
     * only half the users are affected. `None` in turn requires `Secure`.
     *
     * Nothing is lost by not having `SameSite` protect this cookie: it carries
     * no authority on its own. It names no user, grants nothing, and is useful
     * only to whoever also holds the matching unspent `state`.
     *
     * `Secure` on a deployment served over plain HTTP would mean the cookie is
     * never sent and OAuth never completes — which is the correct failure, and
     * the reason there is no flag to turn it off. Local development on
     * `http://localhost:8000` is unaffected: browsers treat `localhost` as a
     * trustworthy origin and accept `Secure` (and `__Host-`) cookies there.
     * Verified in Chromium against this exact attribute string rather than
     * assumed; Firefox has done the same since 75.
     *
     * `httpOnly` because no script has any reason to read it, and `raw: false`
     * so the value is URL-encoded — belt and braces for a value that is already
     * hex.
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
     * That is every FAILURE exit from the callback, and the SUCCESS exit from
     * the exchange — not the callback's success exit, which has just issued a
     * login code bound to this very value. Leaving the cookie in the browser
     * beyond that would be a durable value set by an unauthenticated endpoint
     * and serving no purpose, which is the shape of a tracking cookie even when
     * the contents are meaningless.
     *
     * The attributes must match the ones it was set with, or the browser treats
     * this as a different cookie and clears nothing. That is the reason there is
     * one issue() and one clearFrom() rather than a second binding minted at
     * code-issue time: two set-cookie sites are two places for these six
     * attributes to drift apart, and the drift fails silently.
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
