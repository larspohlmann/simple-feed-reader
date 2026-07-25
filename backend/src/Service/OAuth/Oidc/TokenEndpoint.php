<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc;

use App\Exception\OAuth\OAuthFailedException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One provider's OIDC token endpoint: exchange an authorization code for an ID
 * token, over a connection whose properties we can actually vouch for.
 *
 * ## Why this is its own class
 *
 * {@see IdTokenVerifier} does not check the ID token's signature, and is only
 * entitled to skip that because of the channel the token arrived on. That makes
 * the channel a security control rather than plumbing — so it gets a class, a
 * name, and tests of its own, instead of being a method whose options someone
 * later tidies away into a shared default.
 *
 * The three properties the exemption needs, all enforced below:
 *
 * 1. **The endpoint is ours, not the token's.** $url is passed in from a
 *    constant in the calling provider. No claim, header or request parameter
 *    picks it, so a token cannot nominate the authority that vouches for it.
 * 2. **The connection really is validated TLS.** `verify_peer` and
 *    `verify_host` are restated here even though they are Symfony's defaults,
 *    and a non-`https` endpoint is refused before the request is made. A future
 *    `framework.http_client.default_options` edit in another file therefore
 *    cannot withdraw the premise.
 * 3. **The communication is direct.** `max_redirects` is 0. A followed redirect
 *    would mean the bytes arrived from a host other than the one we pinned,
 *    which is precisely the case the spec's carve-out excludes.
 *
 * This class is therefore the ONLY place that constructs an {@see IdToken}.
 * That is what lets the verifier state its precondition as a parameter type;
 * OidcBoundaryTest fails the build if a second construction site appears.
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
     * @param string $url         MUST be an `https://` URL and MUST NOT be derived
     *                            from anything in the request — see above
     * @param string $redirectUri echoed to the provider, and must match what is
     *                            registered with it byte for byte
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $url,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
    }

    public function fetch(string $code, string $codeVerifier): IdToken
    {
        if (!str_starts_with($this->url, 'https://')) {
            // The signature exemption is only available over validated TLS.
            // Without it nothing is left attesting who minted the token, so the
            // request is never made rather than made and half-trusted.
            throw new OAuthFailedException('token endpoint is not https');
        }

        return new IdToken($this->readIdToken($this->post($code, $codeVerifier)));
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
        } catch (HttpClientExceptionInterface $e) {
            // Covers transport failures, every non-2xx and an undecodable body,
            // since toArray() throws on all three. The provider's own error
            // code is useful in a log and useless — or worse — in a response.
            throw new OAuthFailedException('token endpoint call failed', $e);
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
