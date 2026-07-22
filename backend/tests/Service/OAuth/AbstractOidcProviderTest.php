<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Exception\OAuth\OAuthFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AbstractOidcProviderTest extends TestCase
{
    private const NONCE = 'the-nonce';

    /**
     * A fixed instant, so `exp` in every fixture below is stated relative to a
     * clock the test controls rather than to the wall clock. Expiry is the one
     * check whose boundary a wall-clock test can only approach, never sit on.
     */
    private const NOW = '2026-07-21 12:00:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW, 'UTC');
    }

    public function testAWellFormedResponseYieldsAVerifiedIdentity(): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'email' => 'Bob@Example.com',
            'email_verified' => true,
        ])));

        $identity = $provider->exchangeCode('the-code', 'the-verifier', self::NONCE);

        self::assertSame('stub', $identity->provider);
        self::assertSame('sub-123', $identity->providerUserId);
        self::assertSame('bob@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
    }

    public function testAppleStyleStringBooleansAreUnderstood(): void
    {
        // Apple sends email_verified as the STRING "true". Reading it with a
        // loose cast would make the string "false" verified as well, so both
        // spellings are handled explicitly.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'email' => 'bob@example.com',
            'email_verified' => 'true',
        ])));

        self::assertTrue($provider->exchangeCode('c', 'v', self::NONCE)->emailVerified);
    }

    /**
     * Everything that is not a JSON `true` or the string `"true"` must read as
     * unverified. Neither Google nor Apple sends any of these, which is exactly
     * why they are here: if a provider ever starts to, the safe reading is "not
     * verified" — that downgrades an account link to a fresh signup, whereas
     * the opposite mistake hands a stranger an existing account.
     *
     * `"TRUE"` is deliberately NOT accepted. Case-folding the claim would be a
     * guess about a provider that does not exist, and the guess only ever
     * errs towards trusting more.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unverifiedValues(): iterable
    {
        yield 'json false' => [false];
        yield 'string false' => ['false'];
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
        yield 'uppercase true' => ['TRUE'];
        yield 'mixed case true' => ['True'];
        yield 'string yes' => ['yes'];
        yield 'padded true' => [' true'];
        yield 'null' => [null];
        yield 'absent' => ['__absent__'];
        yield 'array' => [['true']];
    }

    #[DataProvider('unverifiedValues')]
    public function testOnlyTrueAndTheStringTrueMeanVerified(mixed $value): void
    {
        $extra = '__absent__' === $value ? [] : ['email_verified' => $value];

        $provider = $this->provider($this->tokenResponse($this->claims(
            ['email' => 'bob@example.com'] + $extra,
        )));

        self::assertFalse($provider->exchangeCode('c', 'v', self::NONCE)->emailVerified);
    }

    public function testAMismatchedNonceIsRejected(): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'nonce' => 'somebody-elses-nonce',
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAnEmptyExpectedNonceCannotSatisfyTheNonceCheck(): void
    {
        // The nonce check is `hash_equals($expected, $claim)`, which is true
        // when both sides are ''. If a caller ever passed an empty expected
        // nonce — a bug in the state store, a truncated cache read — a token
        // carrying `"nonce": ""` would sail through the one check that ties it
        // to this browser. Rejected before the comparison, so the equality is
        // never asked to defend itself.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'nonce' => '',
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', '');
    }

    public function testAnAbsentNonceIsRejected(): void
    {
        $claims = $this->claims();
        unset($claims['nonce']);

        $this->expectException(OAuthFailedException::class);
        $this->provider($this->tokenResponse($claims))->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAMismatchedAudienceIsRejected(): void
    {
        // An ID token minted for a different client is a valid, correctly
        // signed token that says nothing about OUR relying party. Accepting it
        // would let anyone with any client id at this provider sign in here.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'aud' => 'somebody-elses-client-id',
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAMultiValuedAudienceContainingOurClientIdIsAccepted(): void
    {
        // RFC 7519 §4.1.3 allows `aud` to be an array.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'aud' => ['another-client-id', 'test-client-id'],
        ])));

        self::assertSame('sub-123', $provider->exchangeCode('c', 'v', self::NONCE)->providerUserId);
    }

    public function testAMultiValuedAudienceWithoutOurClientIdIsRejected(): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'aud' => ['another-client-id', 'a-third-client-id'],
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testANestedAudienceArrayIsRejected(): void
    {
        // `aud` as [["test-client-id"]] must not match: the loop compares only
        // string members, so a nested array falls through to "no match".
        $provider = $this->provider($this->tokenResponse($this->claims([
            'aud' => [['test-client-id']],
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAnAuthorizedPartyNamingAnotherClientIsRejected(): void
    {
        // OpenID Connect Core §3.1.3.7 item 5. A token whose `aud` lists us but
        // whose `azp` names somebody else was issued TO that somebody else. It
        // cannot reach us through our own token endpoint call, but the check
        // costs one comparison and removes the need to reason about that.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'aud' => ['test-client-id', 'another-client-id'],
            'azp' => 'another-client-id',
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAnAuthorizedPartyNamingUsIsAccepted(): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'azp' => 'test-client-id',
        ])));

        self::assertSame('sub-123', $provider->exchangeCode('c', 'v', self::NONCE)->providerUserId);
    }

    public function testAMismatchedIssuerIsRejected(): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'iss' => 'https://evil.test',
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'exp' => $this->now() - 61,
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testATokenInsideTheSkewToleranceIsAccepted(): void
    {
        // One second past expiry is clock drift, not an attack. The MockClock
        // is what makes this boundary assertable at all.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'exp' => $this->now() - 1,
        ])));

        self::assertSame('sub-123', $provider->exchangeCode('c', 'v', self::NONCE)->providerUserId);
    }

    public function testAnExpiryGivenAsANumericStringIsRejected(): void
    {
        // RFC 7519 says `exp` is a JSON number. A string that PHP would happily
        // compare as a number is a token built by something that is not the
        // provider, so it is refused rather than coerced.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'exp' => (string) ($this->now() + 300),
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAnAbsentExpiryIsRejected(): void
    {
        $claims = $this->claims();
        unset($claims['exp']);

        $this->expectException(OAuthFailedException::class);
        $this->provider($this->tokenResponse($claims))->exchangeCode('c', 'v', self::NONCE);
    }

    /**
     * Every one of these collapses two provider accounts onto one
     * `user_identity` row, or one provider account onto two — which is the same
     * defect the empty-subject check above exists to prevent, wearing a hat.
     * The whitespace-only case in particular passed a bare `'' === $sub` check.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableSubjects(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
        yield 'tab only' => ["\t"];
        yield 'newline only' => ["\n"];
        yield 'leading space' => [' sub-123'];
        yield 'trailing space' => ['sub-123 '];
        yield 'embedded nul' => ["sub\0123"];
        yield 'embedded control char' => ["sub\x01123"];
        yield 'embedded delete char' => ["sub\x7f123"];
    }

    #[DataProvider('unusableSubjects')]
    public function testAnUnusableSubjectIsRejected(string $subject): void
    {
        $provider = $this->provider($this->tokenResponse($this->claims([
            'sub' => $subject,
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAnInternalSpaceInTheSubjectIsAllowed(): void
    {
        // Only surrounding whitespace and control characters are refused. The
        // rule is about collisions, not about what a provider is allowed to
        // consider pretty, so an otherwise ordinary identifier is left alone.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'sub' => 'sub 123',
        ])));

        self::assertSame('sub 123', $provider->exchangeCode('c', 'v', self::NONCE)->providerUserId);
    }

    public function testANonStringSubjectIsRejected(): void
    {
        // Refused rather than cast: `(string) 123` and the string "123" would
        // be the same UserIdentity row, so accepting both spellings invites a
        // collision the unique index cannot see coming.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'sub' => 123,
        ])));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testANonStringEmailBecomesNoEmail(): void
    {
        // A structured `email` claim is not an address. It must not become the
        // string "Array" or reach OAuthIdentity at all.
        $provider = $this->provider($this->tokenResponse($this->claims([
            'email' => ['bob@example.com'],
            'email_verified' => true,
        ])));

        self::assertNull($provider->exchangeCode('c', 'v', self::NONCE)->email);
    }

    public function testAnErrorFromTheTokenEndpointIsRejected(): void
    {
        $provider = $this->provider(new MockResponse(
            '{"error":"invalid_grant"}',
            ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']],
        ));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAResponseWithNoIdTokenIsRejected(): void
    {
        $provider = $this->provider(new MockResponse(
            '{"access_token":"at"}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAMalformedIdTokenIsRejected(): void
    {
        $provider = $this->provider(new MockResponse(
            '{"id_token":"not.a.jwt.at.all"}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAPayloadThatIsNotAJsonObjectIsRejected(): void
    {
        // A JSON array decodes to a PHP array too, so `is_array()` alone would
        // wave it through to the claim reads and only fail by accident.
        $provider = $this->provider($this->tokenResponseFromPayload('["iss","aud"]'));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testAPayloadThatIsNotJsonAtAllIsRejected(): void
    {
        $provider = $this->provider($this->tokenResponseFromPayload('not json'));

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testANonHttpsTokenEndpointIsRefused(): void
    {
        // The signature-verification exemption this class relies on is only
        // available over a validated TLS connection. Without TLS there is
        // nothing left checking who minted the token, so the request is not
        // made at all.
        $provider = new StubOidcProvider(
            new MockHttpClient($this->tokenResponse($this->claims())),
            $this->clock,
            'http://issuer.test/token',
        );

        $this->expectException(OAuthFailedException::class);
        $provider->exchangeCode('c', 'v', self::NONCE);
    }

    public function testTheTokenRequestCarriesTheCodeVerifierAndCredentials(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return $this->tokenResponse($this->claims());
        });

        (new StubOidcProvider($client, $this->clock))->exchangeCode('the-code', 'the-verifier', self::NONCE);

        self::assertNotNull($seen);
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://issuer.test/token', $seen['url']);

        $body = $seen['options']['body'] ?? '';
        $body = \is_string($body) ? $body : '';
        parse_str($body, $fields);
        self::assertSame('authorization_code', $fields['grant_type'] ?? null);
        self::assertSame('the-code', $fields['code'] ?? null);
        self::assertSame('the-verifier', $fields['code_verifier'] ?? null);
        self::assertSame('test-client-id', $fields['client_id'] ?? null);
        self::assertSame('test-client-secret', $fields['client_secret'] ?? null);
        self::assertSame('https://app.test/api/auth/oauth/stub/callback', $fields['redirect_uri'] ?? null);
    }

    public function testTheTokenRequestPinsTlsAndRefusesRedirects(): void
    {
        // Both options are Symfony's defaults for verification and are NOT the
        // default for redirects. They are restated at the call site because the
        // signature exemption stands on them: a global `default_options` change
        // three files away must not be able to quietly withdraw it, and a
        // followed redirect would mean the token no longer came "directly from
        // the token endpoint" the way the spec's carve-out requires.
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options;

            return $this->tokenResponse($this->claims());
        });

        (new StubOidcProvider($client, $this->clock))->exchangeCode('c', 'v', self::NONCE);

        self::assertNotNull($seen);
        self::assertTrue($seen['verify_peer'] ?? null);
        self::assertTrue($seen['verify_host'] ?? null);
        self::assertSame(0, $seen['max_redirects'] ?? null);
    }

    public function testEveryFailureLooksIdenticalToTheCaller(): void
    {
        // The caller must not be able to tell "no such client" from "expired
        // token" from "the provider is down". Only the log-only $logDetail may
        // differ; everything the problem document is built from must not.
        $failures = [
            $this->tokenResponse($this->claims(['iss' => 'https://evil.test'])),
            $this->tokenResponse($this->claims(['aud' => 'nope'])),
            $this->tokenResponse($this->claims(['exp' => $this->now() - 999])),
            $this->tokenResponse($this->claims(['nonce' => 'nope'])),
            $this->tokenResponse($this->claims(['sub' => ''])),
            new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400]),
            new MockResponse('{"id_token":"garbage"}', [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ];

        $shapes = [];
        foreach ($failures as $response) {
            try {
                $this->provider($response)->exchangeCode('c', 'v', self::NONCE);
                self::fail('expected the exchange to fail');
            } catch (OAuthFailedException $e) {
                $shapes[] = [$e->type, $e->status, $e->title, $e->detail, $e->errors];
            }
        }

        self::assertCount(\count($failures), $shapes);
        self::assertCount(1, array_unique(array_map(
            static fn (array $shape): string => json_encode($shape, \JSON_THROW_ON_ERROR),
            $shapes,
        )));
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function provider(MockResponse $response): StubOidcProvider
    {
        return new StubOidcProvider(new MockHttpClient($response), $this->clock);
    }

    /**
     * A token that passes every check, with $overrides applied last so a test
     * states only the one claim it is attacking.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            'sub' => 'sub-123',
            'aud' => 'test-client-id',
            'iss' => 'https://issuer.test',
            'exp' => $this->now() + 300,
            'nonce' => self::NONCE,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function tokenResponse(array $claims): MockResponse
    {
        return $this->tokenResponseFromPayload(json_encode($claims, \JSON_THROW_ON_ERROR));
    }

    private function tokenResponseFromPayload(string $payload): MockResponse
    {
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $idToken = $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode($payload) . '.signature';

        return new MockResponse(
            json_encode(['id_token' => $idToken], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
