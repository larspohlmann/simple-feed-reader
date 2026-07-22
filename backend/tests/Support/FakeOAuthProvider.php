<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\OAuthFailedException;
use App\Service\OAuth\OAuthProviderInterface;

/**
 * Stands in for Google at the network boundary, so the flow tests exercise
 * every one of our own moving parts — state, PKCE, login code, linking, status
 * gate, JWT — without a network.
 *
 * The real providers' own code is covered separately: AbstractOidcProviderTest
 * drives the token exchange and every ID-token check through MockHttpClient,
 * and the two provider tests pin their authorization URLs. What is NOT covered
 * anywhere, by design, is the providers' actual behaviour — that is the
 * boundary the design spec draws.
 *
 * Not `readonly`: the recorders below are the only way a test can see the
 * state, nonce and challenge the controller minted, since those values leave
 * the server only inside a URL we hand to the provider.
 */
final class FakeOAuthProvider implements OAuthProviderInterface
{
    public ?string $lastState = null;
    public ?string $lastNonce = null;
    public ?string $lastCodeChallenge = null;

    /** @var list<array{code: string, codeVerifier: string, nonce: string}> */
    public array $exchanges = [];

    public function __construct(
        private readonly OAuthIdentity $identity,
        private readonly bool $failExchange = false,
    ) {
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

    public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentity
    {
        // Recorded rather than merely counted, so a test can assert the
        // controller forwarded the PKCE verifier and nonce belonging to THIS
        // flow — the two values a state mix-up would get wrong.
        $this->exchanges[] = ['code' => $code, 'codeVerifier' => $codeVerifier, 'nonce' => $nonce];

        if ($this->failExchange) {
            throw new OAuthFailedException('fake provider was told to fail');
        }

        return $this->identity;
    }
}
