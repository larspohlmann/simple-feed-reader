<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Oidc;

use App\Exception\OAuth\OAuthFailedException;
use App\Service\OAuth\Oidc\TokenEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The transport half, and the three preconditions the signature-verification
 * exemption stands on.
 *
 * Those preconditions are the reason this class exists as its own unit rather
 * than as a method: an ID token is trusted here because of WHERE it came from,
 * so the code that decides where it came from is worth isolating and testing on
 * its own.
 */
final class TokenEndpointTest extends TestCase
{
    private const URL = 'https://issuer.test/token';

    public function testAnIdTokenIsReturnedFromAWellFormedResponse(): void
    {
        $endpoint = $this->endpoint($this->response('{"id_token":"the.id.token"}'));

        self::assertSame('the.id.token', $endpoint->fetch('the-code', 'the-verifier')->jwt);
    }

    public function testTheRequestCarriesTheCodeVerifierAndCredentials(): void
    {
        $seen = null;
        $endpoint = $this->endpoint(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return $this->response('{"id_token":"the.id.token"}');
        });

        $endpoint->fetch('the-code', 'the-verifier');

        self::assertNotNull($seen);
        self::assertSame('POST', $seen['method']);
        self::assertSame(self::URL, $seen['url']);

        $body = $seen['options']['body'] ?? '';
        parse_str(\is_string($body) ? $body : '', $fields);
        self::assertSame('authorization_code', $fields['grant_type'] ?? null);
        self::assertSame('the-code', $fields['code'] ?? null);
        self::assertSame('the-verifier', $fields['code_verifier'] ?? null);
        self::assertSame('test-client-id', $fields['client_id'] ?? null);
        self::assertSame('test-client-secret', $fields['client_secret'] ?? null);
        self::assertSame('https://app.test/callback', $fields['redirect_uri'] ?? null);
    }

    public function testTheRequestPinsTlsAndRefusesRedirects(): void
    {
        // The three options the class docblock's security argument rests on.
        // They are restated at the call site rather than left to global
        // defaults so a `framework.http_client.default_options` edit in another
        // file cannot quietly withdraw the premise.
        $seen = null;
        $endpoint = $this->endpoint(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options;

            return $this->response('{"id_token":"the.id.token"}');
        });

        $endpoint->fetch('c', 'v');

        self::assertNotNull($seen);
        self::assertTrue($seen['verify_peer'] ?? null);
        self::assertTrue($seen['verify_host'] ?? null);
        self::assertSame(0, $seen['max_redirects'] ?? null);
    }

    public function testTheRequestBoundsBothTheIdleAndTheTotalTime(): void
    {
        // `timeout` alone resets on every byte, so a provider that dribbles a
        // response could otherwise hold a PHP-FPM worker indefinitely.
        $seen = null;
        $endpoint = $this->endpoint(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options;

            return $this->response('{"id_token":"the.id.token"}');
        });

        $endpoint->fetch('c', 'v');

        self::assertNotNull($seen);
        self::assertIsNumeric($seen['timeout'] ?? null);
        self::assertIsNumeric($seen['max_duration'] ?? null);
        self::assertGreaterThan(0, $seen['max_duration']);
    }

    public function testANonHttpsEndpointIsRefusedWithoutMakingTheRequest(): void
    {
        // Without validated TLS nothing is left attesting who minted the token,
        // so the request is never made rather than made and half-trusted.
        $called = false;
        $client = new MockHttpClient(function () use (&$called) {
            $called = true;

            return $this->response('{"id_token":"the.id.token"}');
        });

        $endpoint = new TokenEndpoint(
            $client,
            'http://issuer.test/token',
            'test-client-id',
            'test-client-secret',
            'https://app.test/callback',
        );

        $this->assertFailsWith('token endpoint is not https', $endpoint);
        self::assertFalse($called, 'the request must not be made at all');
    }

    public function testAnErrorStatusFromTheEndpointIsRejected(): void
    {
        $endpoint = $this->endpoint(new MockResponse('{"error":"invalid_grant"}', [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $this->assertFailsWith('token endpoint call failed', $endpoint);
    }

    public function testAnUndecodableBodyIsRejected(): void
    {
        $this->assertFailsWith('token endpoint call failed', $this->endpoint($this->response('not json')));
    }

    public function testAResponseWithNoIdTokenIsRejected(): void
    {
        $this->assertFailsWith(
            'token response carried no id_token',
            $this->endpoint($this->response('{"access_token":"at"}')),
        );
    }

    public function testANonStringIdTokenIsRejected(): void
    {
        $this->assertFailsWith(
            'token response carried no id_token',
            $this->endpoint($this->response('{"id_token":42}')),
        );
    }

    public function testABlankIdTokenIsRejected(): void
    {
        $this->assertFailsWith(
            'token response carried no id_token',
            $this->endpoint($this->response('{"id_token":""}')),
        );
    }

    private function assertFailsWith(string $logDetail, TokenEndpoint $endpoint): void
    {
        try {
            $endpoint->fetch('c', 'v');
        } catch (OAuthFailedException $e) {
            self::assertSame($logDetail, $e->logDetail);

            return;
        }

        self::fail('expected the fetch to fail');
    }

    private function response(string $body): MockResponse
    {
        return new MockResponse($body, [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function endpoint(MockResponse|callable $response): TokenEndpoint
    {
        return new TokenEndpoint(
            new MockHttpClient($response),
            self::URL,
            'test-client-id',
            'test-client-secret',
            'https://app.test/callback',
        );
    }
}
