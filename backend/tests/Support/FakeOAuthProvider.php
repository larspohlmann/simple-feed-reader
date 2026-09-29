<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Model\OAuthIdentityModel;
use App\Service\OAuth\OAuthProvider\OAuthProviderInterface;

/**
 * Stands in for Google at the network boundary so the flow tests run every part of ours without a network. Not
 * readonly: the recorders are the only way a test sees the state, nonce and challenge the controller minted.
 */
final class FakeOAuthProvider implements OAuthProviderInterface
{
    public ?string $lastState = null;
    public ?string $lastNonce = null;
    public ?string $lastCodeChallenge = null;

    /** @var list<array{code: string, codeVerifier: string, nonce: string}> */
    public array $exchanges = [];

    private function __construct(
        private readonly OAuthIdentityModel $identity,
        private readonly ?string $exchangeFailure,
    ) {
    }

    public static function returning(OAuthIdentityModel $identity): self
    {
        return new self($identity, null);
    }

    public static function failingExchange(OAuthIdentityModel $identity): self
    {
        return new self($identity, 'fake provider was told to fail');
    }

    public function getName(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $this->lastState = $state;
        $this->lastNonce = $nonce;
        $this->lastCodeChallenge = $codeChallenge;

        return 'https://provider.test/authorize?state=' . urlencode($state);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentityModel
    {
        // Recorded rather than merely counted, so a test can assert the
        // controller forwarded the PKCE verifier and nonce belonging to THIS
        // flow — the two values a state mix-up would get wrong.
        $this->exchanges[] = ['code' => $code, 'codeVerifier' => $codeVerifier, 'nonce' => $nonce];

        if (null !== $this->exchangeFailure) {
            throw new OAuthFailedException($this->exchangeFailure);
        }

        return $this->identity;
    }
}
