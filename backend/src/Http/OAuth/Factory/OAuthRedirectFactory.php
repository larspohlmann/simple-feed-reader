<?php

declare(strict_types=1);

namespace App\Http\OAuth\Factory;

use App\Http\OAuth\FlowCookie;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The two redirects back to the SPA. They carry only a login code minted here or a reason literal, never a JWT, a
 * provider code or caller input, and the host is APP_FRONTEND_URL, never the request's: anything else is an open
 * redirect that hands an attacker's page a login code.
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

    /** Clears the flow cookie here, so no failure exit of OAuthController::callback() can forget it. */
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
