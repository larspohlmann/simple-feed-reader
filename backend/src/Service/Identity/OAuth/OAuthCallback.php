<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Service\OAuth\Exception\InvalidOAuthStateException;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Model\OAuthCallbackAttemptModel;
use App\Service\OAuth\Model\OAuthCallbackFailure;
use App\Service\OAuth\Model\OAuthIdentityModel;
use App\Service\OAuth\Model\OAuthStartStateModel;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Random\RandomException;

final readonly class OAuthCallback
{
    public function __construct(
        private OAuthStateStore $stateStore,
        private OAuthProviderRegistry $providers,
        private OAuthSignIn $signIn,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OAuthCallbackRefusedException
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function complete(OAuthCallbackAttemptModel $attempt): string
    {
        if ($attempt->declined) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::AccessDenied);
        }

        $state = $attempt->state;
        $code = $attempt->code;
        if (null === $state || null === $code) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::InvalidRequest);
        }

        $started = $this->consume($state, $attempt);
        $identity = $this->exchange($attempt->provider, $code, $started);

        // consume() refuses a null token, so a matched state proves the cookie was present.
        \assert(null !== $attempt->browserToken);

        return $this->signIn->issueLoginCode($identity, $attempt->browserToken);
    }

    /** @throws InvalidArgumentException */
    private function consume(string $state, OAuthCallbackAttemptModel $attempt): OAuthStartStateModel
    {
        try {
            $started = $this->stateStore->consume($state, $attempt->browserToken);
        } catch (InvalidOAuthStateException) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::InvalidState);
        }

        // A state replayed at another provider's callback is refused like a forged one.
        if ($started->provider !== $attempt->provider) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::InvalidState);
        }

        return $started;
    }

    private function exchange(string $provider, string $code, OAuthStartStateModel $started): OAuthIdentityModel
    {
        try {
            return $this->providers->get($provider)->exchangeCode($code, $started->codeVerifier, $started->nonce);
        } catch (OAuthFailedException $failure) {
            $this->logger->warning('OAuth exchange failed', [
                'provider' => $provider,
                'detail' => $failure->logDetail,
                'exception' => $failure->getPrevious(),
            ]);

            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::ExchangeFailed);
        }
    }
}
