<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Builds the two redirects back to the SPA from the OAuth callback.
 *
 * Note what does not appear in either URL, on success or failure: a JWT, an
 * authorization code, a state value, or anything else the caller supplied. The
 * only success payload is a one-time login code the server just minted; the only
 * failure payload is a fixed reason literal passed by callback(). The host comes
 * from APP_FRONTEND_URL, a deployment-time value nobody can influence over HTTP —
 * which keeps this from being an open redirect that hands the attacker's page a
 * fresh login code.
 */
final readonly class OAuthRedirectFactory
{
    public function __construct(
        private FlowCookie $flowCookie,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    /**
     * The success redirect. The login code is issued server-side and is bound to
     * the flow cookie, which is deliberately NOT cleared here — the exchange one
     * hop later needs the binding back. See OAuthController::callback().
     */
    public function success(string $loginCode): RedirectResponse
    {
        return new RedirectResponse(\sprintf(
            '%s/auth/callback?code=%s',
            $this->frontendBaseUrl(),
            urlencode($loginCode),
        ));
    }

    /**
     * A redirect back to the SPA carrying a reason code instead of a session.
     *
     * Clearing the flow cookie belongs here: it puts the clear on every failure
     * exit from callback() at once, so a new one added later cannot forget it and
     * silently leak a durable cookie.
     */
    public function failure(string $reason): RedirectResponse
    {
        return $this->flowCookie->clearFrom(new RedirectResponse(\sprintf(
            '%s/auth/callback?error=%s',
            $this->frontendBaseUrl(),
            urlencode($reason),
        )));
    }

    private function frontendBaseUrl(): string
    {
        return rtrim($this->frontendUrl, '/');
    }
}
