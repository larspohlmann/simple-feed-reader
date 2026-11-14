<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc\Pass;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Oidc\Model\IdTokenModel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exchanges an authorization code for an ID token over a channel that stands in for its signature: a constant https
 * URL, peer and host verification restated, no redirects. The only constructor of {@see IdTokenModel}
 * (OidcBoundaryTest). docs/oauth-sign-in.md#the-id-token-trust-boundary
 */
final readonly class TokenEndpoint
{
    /**
     * Inactivity timeout and total wall-clock budget for the token call. The
     * second one matters: `timeout` alone resets on every byte, so a provider
     * that dribbles a response can hold a PHP-FPM worker indefinitely.
     */
    private const int REQUEST_TIMEOUT_SECONDS = 10;
    private const int REQUEST_MAX_DURATION_SECONDS = 15;

    /**
     * @param string $url a constant of the calling provider, never derived from the request
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $url,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
    }

    public function fetch(string $code, string $codeVerifier): IdTokenModel
    {
        if (!str_starts_with($this->url, 'https://')) {
            // The signature exemption is only available over validated TLS.
            // Without it nothing is left attesting who minted the token, so the
            // request is never made rather than made and half-trusted.
            throw new OAuthFailedException('token endpoint is not https');
        }

        return new IdTokenModel($this->readIdToken($this->post($code, $codeVerifier)));
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $code, string $codeVerifier): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                    'redirect_uri' => $this->redirectUri,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                // The three options this class's security argument rests on.
                // See the class docblock; they are restated here rather than
                // left to global defaults on purpose.
                'verify_peer' => true,
                'verify_host' => true,
                'max_redirects' => 0,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::REQUEST_MAX_DURATION_SECONDS,
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();

            return $payload;
        } catch (HttpClientExceptionInterface $exception) {
            // Covers transport failures, every non-2xx and an undecodable body,
            // since toArray() throws on all three. The provider's own error
            // code is useful in a log and useless — or worse — in a response.
            throw new OAuthFailedException('token endpoint call failed', $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return non-empty-string
     */
    private function readIdToken(array $payload): string
    {
        $idToken = $payload['id_token'] ?? null;
        if (!\is_string($idToken) || '' === $idToken) {
            throw new OAuthFailedException('token response carried no id_token');
        }

        return $idToken;
    }
}
