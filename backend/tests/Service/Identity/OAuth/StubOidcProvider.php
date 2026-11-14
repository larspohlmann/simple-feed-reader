<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\OAuthProvider\AbstractOidcProvider;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A configurable AbstractOidcProvider. The token endpoint is a constructor argument so a test can hand it an `http://`
 * URL and see the scheme guard fire.
 */
final readonly class StubOidcProvider extends AbstractOidcProvider
{
    public function __construct(
        HttpClientInterface $httpClient,
        ClockInterface $clock,
        private string $tokenEndpoint = 'https://issuer.test/token',
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

    protected function getAuthorizationEndpoint(): string
    {
        return 'https://issuer.test/authorize';
    }

    protected function getScope(): string
    {
        return 'openid email';
    }

    protected function getTokenEndpointUrl(): string
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
