<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\AbstractOidcProvider;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exercises AbstractOidcProvider without pinning the tests to either real
 * provider's endpoints or credentials.
 *
 * The token endpoint is a constructor argument rather than a constant so one
 * test can point it at an `http://` URL and assert the scheme guard fires —
 * that guard is the precondition of the whole signature-verification exemption,
 * so it needs a test that can actually violate it.
 */
final class StubOidcProvider extends AbstractOidcProvider
{
    public function __construct(
        HttpClientInterface $httpClient,
        ClockInterface $clock,
        private readonly string $tokenEndpoint = 'https://issuer.test/token',
    ) {
        parent::__construct($httpClient, $clock, 'https://app.test');
    }

    public function getName(): string
    {
        return 'stub';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        return 'https://issuer.test/authorize';
    }

    protected function getTokenEndpoint(): string
    {
        return $this->tokenEndpoint;
    }

    protected function getIssuers(): array
    {
        return ['https://issuer.test'];
    }

    protected function getClientId(): string
    {
        return 'test-client-id';
    }

    protected function getClientSecret(): string
    {
        return 'test-client-secret';
    }
}
