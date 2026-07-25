<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Oidc;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\OAuthFailedException;
use App\Service\OAuth\Oidc\IdToken;
use App\Service\OAuth\Oidc\IdTokenVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The deciding half of reading an ID token.
 *
 * These are unit tests for the verifier on its own. AbstractOidcProviderTest
 * covers the same rejections through the real entry point — deliberately, not
 * redundantly: these pin each rule and its message, that one pins that the
 * rules are still reached from a live exchange.
 */
final class IdTokenVerifierTest extends TestCase
{
    private const NONCE = 'the-nonce';
    private const CLIENT_ID = 'test-client-id';
    private const ISSUER = 'https://issuer.test';

    /** A fixed instant, so `exp` is stated against a clock the test controls. */
    private const NOW = '2026-07-21 12:00:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW, 'UTC');
    }

    public function testAWellFormedTokenYieldsAnIdentity(): void
    {
        $identity = $this->verify($this->claims([
            'email' => 'Bob@Example.com',
            'email_verified' => true,
        ]));

        self::assertSame('stub', $identity->provider);
        self::assertSame('sub-123', $identity->providerUserId);
        self::assertSame('bob@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
    }

    public function testAnEmptyExpectedNonceIsRefusedBeforeAnyComparison(): void
    {
        // hash_equals('', '') is true, so an empty expectation would accept a
        // token carrying an empty nonce — defeating the one check that ties the
        // token to the browser that started the flow. Refused outright, so the
        // comparison is never asked to defend a value that cannot defend itself.
        $this->assertRejectedWith(
            'no nonce to check the id_token against',
            $this->token($this->claims(['nonce' => ''])),
            expectedNonce: '',
        );
    }

    public function testAMismatchedIssuerIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token issuer mismatch',
            $this->token($this->claims(['iss' => 'https://evil.test'])),
        );
    }

    public function testAnAbsentIssuerIsRejected(): void
    {
        $this->assertRejectedWith('id_token issuer mismatch', $this->token($this->claims([], 'iss')));
    }

    public function testAnyOfTheConfiguredIssuersIsAccepted(): void
    {
        // Google mints tokens with both `https://accounts.google.com` and the
        // bare `accounts.google.com`, which is why the issuer is a list.
        $verifier = new IdTokenVerifier(
            $this->clock,
            'stub',
            self::CLIENT_ID,
            ['https://other.test', self::ISSUER],
        );

        $identity = $verifier->verify($this->token($this->claims()), self::NONCE);

        self::assertSame('sub-123', $identity->providerUserId);
    }

    public function testAMismatchedAudienceIsRejected(): void
    {
        // A correctly signed token minted for a different client says nothing
        // about OUR relying party.
        $this->assertRejectedWith(
            'id_token audience mismatch',
            $this->token($this->claims(['aud' => 'somebody-elses-client-id'])),
        );
    }

    public function testAMultiValuedAudienceContainingOurClientIdIsAccepted(): void
    {
        $identity = $this->verify($this->claims(['aud' => ['another-client-id', self::CLIENT_ID]]));

        self::assertSame('sub-123', $identity->providerUserId);
    }

    public function testANestedAudienceArrayIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token audience mismatch',
            $this->token($this->claims(['aud' => [[self::CLIENT_ID]]])),
        );
    }

    public function testAnAbsentAudienceIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token audience mismatch',
            $this->token($this->claims([], 'aud')),
        );
    }

    public function testAnAuthorizedPartyNamingAnotherClientIsRejected(): void
    {
        // OIDC Core §3.1.3.7 item 5: when `azp` is present it names the client
        // the token was issued to, which may differ from the audience.
        $this->assertRejectedWith(
            'id_token authorized party mismatch',
            $this->token($this->claims([
                'aud' => [self::CLIENT_ID, 'another-client-id'],
                'azp' => 'another-client-id',
            ])),
        );
    }

    public function testANonStringAuthorizedPartyIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token authorized party mismatch',
            $this->token($this->claims(['azp' => 42])),
        );
    }

    public function testAnAuthorizedPartyNamingUsIsAccepted(): void
    {
        self::assertSame('sub-123', $this->verify($this->claims([
            'azp' => self::CLIENT_ID,
        ]))->providerUserId);
    }

    public function testAnAbsentAuthorizedPartyIsAccepted(): void
    {
        // Google omits it in the single-client case and Apple never sends it,
        // so requiring it would reject almost every real token.
        self::assertSame('sub-123', $this->verify($this->claims([], 'azp'))->providerUserId);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token expired or has no exp',
            $this->token($this->claims(['exp' => $this->now() - 61])),
        );
    }

    public function testATokenInsideTheSkewToleranceIsAccepted(): void
    {
        // One second past expiry is clock drift between us and the provider,
        // not an attack.
        self::assertSame('sub-123', $this->verify($this->claims([
            'exp' => $this->now() - 1,
        ]))->providerUserId);
    }

    public function testAnExpiryGivenAsANumericStringIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token expired or has no exp',
            $this->token($this->claims(['exp' => (string) ($this->now() + 300)])),
        );
    }

    public function testAnAbsentExpiryIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token expired or has no exp',
            $this->token($this->claims([], 'exp')),
        );
    }

    public function testAMismatchedNonceIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token nonce mismatch',
            $this->token($this->claims(['nonce' => 'somebody-elses-nonce'])),
        );
    }

    public function testAnAbsentNonceIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token nonce mismatch',
            $this->token($this->claims([], 'nonce')),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSubjects(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
        yield 'tab only' => ["\t"];
        yield 'leading space' => [' sub-123'];
        yield 'trailing space' => ['sub-123 '];
        yield 'embedded nul' => ["sub\0123"];
        yield 'embedded control char' => ["sub\x01123"];
        yield 'embedded delete char' => ["sub\x7f123"];
    }

    #[DataProvider('unusableSubjects')]
    public function testAnUnusableSubjectIsRejected(string $subject): void
    {
        // Every one of these collapses two provider accounts onto one
        // user_identity row, or one provider account onto two.
        $this->assertRejectedWith(
            'id_token carried no usable sub',
            $this->token($this->claims(['sub' => $subject])),
        );
    }

    public function testANonStringSubjectIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token carried no usable sub',
            $this->token($this->claims(['sub' => 123])),
        );
    }

    public function testAnInternalSpaceInTheSubjectIsAllowed(): void
    {
        // The rule is about collisions, not about what a provider is allowed to
        // consider pretty.
        self::assertSame('sub 123', $this->verify($this->claims(['sub' => 'sub 123']))->providerUserId);
    }

    /**
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
        yield 'padded true' => [' true'];
        yield 'null' => [null];
        yield 'array' => [['true']];
    }

    #[DataProvider('unverifiedValues')]
    public function testOnlyTrueAndTheStringTrueMeanVerified(mixed $value): void
    {
        // The two mistakes are not symmetric: reading verified as unverified is
        // an inconvenience, the opposite is an account takeover.
        $identity = $this->verify($this->claims([
            'email' => 'bob@example.com',
            'email_verified' => $value,
        ]));

        self::assertFalse($identity->emailVerified);
    }

    public function testAppleStyleStringBooleansAreUnderstood(): void
    {
        $identity = $this->verify($this->claims([
            'email' => 'bob@example.com',
            'email_verified' => 'true',
        ]));

        self::assertTrue($identity->emailVerified);
    }

    public function testAnAbsentEmailVerifiedClaimReadsAsUnverified(): void
    {
        $identity = $this->verify($this->claims(['email' => 'bob@example.com'], 'email_verified'));

        self::assertFalse($identity->emailVerified);
    }

    public function testANonStringEmailBecomesNoEmail(): void
    {
        // A structured `email` claim is not an address. It must not become the
        // string "Array" or reach OAuthIdentity at all.
        $identity = $this->verify($this->claims([
            'email' => ['bob@example.com'],
            'email_verified' => true,
        ]));

        self::assertNull($identity->email);
    }

    public function testABlankEmailBecomesNoEmail(): void
    {
        self::assertNull($this->verify($this->claims(['email' => '']))->email);
    }

    public function testAMalformedTokenIsRejectedByTheDecoder(): void
    {
        // The verifier does not re-implement decoding; it must still surface a
        // decode failure as the same kind of failure as every other rejection.
        $this->assertRejectedWith('id_token is not a three-segment JWT', new IdToken('garbage'));
    }

    public function testTheIdentityCarriesTheProviderItWasVerifiedFor(): void
    {
        $verifier = new IdTokenVerifier($this->clock, 'apple', self::CLIENT_ID, [self::ISSUER]);

        self::assertSame('apple', $verifier->verify($this->token($this->claims()), self::NONCE)->provider);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function verifier(): IdTokenVerifier
    {
        return new IdTokenVerifier($this->clock, 'stub', self::CLIENT_ID, [self::ISSUER]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verify(array $claims): OAuthIdentity
    {
        return $this->verifier()->verify($this->token($claims), self::NONCE);
    }

    private function assertRejectedWith(
        string $logDetail,
        IdToken $token,
        string $expectedNonce = self::NONCE,
    ): void {
        try {
            $this->verifier()->verify($token, $expectedNonce);
        } catch (OAuthFailedException $e) {
            self::assertSame($logDetail, $e->logDetail);

            return;
        }

        self::fail('expected the token to be rejected');
    }

    /**
     * A token that passes every check, with $overrides applied last so a test
     * states only the one claim it is attacking, and $remove naming a claim to
     * leave out entirely.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides = [], ?string $remove = null): array
    {
        $claims = array_merge([
            'sub' => 'sub-123',
            'aud' => self::CLIENT_ID,
            'iss' => self::ISSUER,
            'exp' => $this->now() + 300,
            'nonce' => self::NONCE,
        ], $overrides);

        if (null !== $remove) {
            unset($claims[$remove]);
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims): IdToken
    {
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        return new IdToken(
            $encode('{"alg":"RS256","typ":"JWT"}')
            . '.' . $encode(json_encode($claims, \JSON_THROW_ON_ERROR))
            . '.signature',
        );
    }
}
