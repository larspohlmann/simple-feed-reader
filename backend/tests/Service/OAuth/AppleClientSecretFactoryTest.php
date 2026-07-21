<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Exception\OAuth\OAuthFailedException;
use App\Service\OAuth\AppleClientSecretFactory;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AppleClientSecretFactoryTest extends TestCase
{
    private const SERVICES_ID = 'test.apple.services.id';
    private const TEAM_ID = 'TESTTEAMID';
    private const KEY_ID = 'TESTKEYID1';

    public function testItProducesAnEs256JwtWithApplesRequiredClaims(): void
    {
        $secret = $this->factory(new MockClock('2026-07-21 12:00:00'))->create();

        [$header, $payload] = self::decodeSegments($secret);

        self::assertSame('ES256', $header['alg'] ?? null);
        // Apple has many keys per team; `kid` is how it knows which public half
        // to check this signature against.
        self::assertSame(self::KEY_ID, $header['kid'] ?? null);

        // The two places Apple inverts the shape you would expect: the issuer is
        // the TEAM, and the subject is the client (the Services ID). Verified
        // against Apple's "Creating a client secret" documentation, not inferred
        // from the generic OIDC private_key_jwt profile, where `iss` and `sub`
        // would both be the client id.
        self::assertSame(self::TEAM_ID, $payload['iss'] ?? null);
        self::assertSame(self::SERVICES_ID, $payload['sub'] ?? null);
        self::assertSame('https://appleid.apple.com', $payload['aud'] ?? null);

        $issuedAt = (new \DateTimeImmutable('2026-07-21 12:00:00'))->getTimestamp();
        self::assertSame($issuedAt, $payload['iat'] ?? null);
        // Apple rejects secrets valid for more than six months. We use one
        // hour: the secret is minted per request, so a long life buys nothing
        // and only widens the window on a leaked one.
        self::assertSame($issuedAt + 3600, $payload['exp'] ?? null);
    }

    /**
     * Every other assertion in this file would hold for a token signed with the
     * wrong key, or with a signature of zero bytes. This is the one that says
     * Apple would actually accept it.
     */
    public function testTheSignatureVerifiesAgainstThePublicHalfOfTheKey(): void
    {
        $secret = $this->factory(new MockClock('2026-07-21 12:00:00'))->create();
        if ('' === $secret) {
            self::fail('the factory produced an empty secret');
        }

        $publicKey = self::publicKey();
        if ('' === $publicKey) {
            self::fail('the public-key fixture is empty');
        }

        $token = (new Parser(new JoseEncoder()))->parse($secret);

        self::assertTrue(
            (new Validator())->validate(
                $token,
                new SignedWith(new Sha256(), InMemory::plainText($publicKey)),
            ),
        );
    }

    /**
     * Guards a defect that a fixed-second clock cannot see.
     *
     * `ChainedFormatter::default()` renders date claims through
     * MicrosecondBasedDateConversion, which emits a JSON *float*
     * (1784030400.123456) whenever the instant carries microseconds — and
     * returns a plain int only when they happen to be zero. Every clock in the
     * tests is a MockClock on a whole second; the clock in production is
     * NativeClock, which never is. So the naive spelling passes the suite and
     * signs a token whose `iat` and `exp` are floats against Apple, which wants
     * NumericDate integers. Asserting on a microsecond-bearing instant is what
     * keeps `withUnixTimestampDates()` in the factory from being "simplified"
     * back to the default.
     */
    public function testTimestampsAreIntegersEvenWhenTheClockCarriesMicroseconds(): void
    {
        $secret = $this->factory(new MockClock('2026-07-21 12:00:00.123456'))->create();

        [, $payload] = self::decodeSegments($secret);

        self::assertIsInt($payload['iat'] ?? null);
        self::assertIsInt($payload['exp'] ?? null);
        self::assertSame(
            (new \DateTimeImmutable('2026-07-21 12:00:00'))->getTimestamp(),
            $payload['iat'],
        );
    }

    public function testItReportsUnconfiguredWhenTheKeyIsMissing(): void
    {
        self::assertFalse($this->factoryWith('', '')->isConfigured());
    }

    /**
     * A half-filled env block is a likelier deployment mistake than an empty
     * one — somebody pastes the key and forgets the team id. Each field is
     * load-bearing, so any one of them missing means "this deployment does not
     * offer Apple", never "offer it and fail at the token endpoint".
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function partialConfigurations(): iterable
    {
        $key = self::privateKey();

        yield 'no services id' => ['', self::TEAM_ID, self::KEY_ID, $key];
        yield 'no team id' => [self::SERVICES_ID, '', self::KEY_ID, $key];
        yield 'no key id' => [self::SERVICES_ID, self::TEAM_ID, '', $key];
        yield 'no private key' => [self::SERVICES_ID, self::TEAM_ID, self::KEY_ID, ''];
    }

    #[DataProvider('partialConfigurations')]
    public function testAPartiallyConfiguredDeploymentIsNotConfigured(
        string $servicesId,
        string $teamId,
        string $keyId,
        string $privateKey,
    ): void {
        $factory = new AppleClientSecretFactory(
            new MockClock('2026-07-21 12:00:00'),
            $servicesId,
            $teamId,
            $keyId,
            $privateKey,
        );

        self::assertFalse($factory->isConfigured());
    }

    /**
     * The three ways a private key reaches us broken: not a key at all, the
     * right shape but the wrong algorithm, and a PEM whose newlines a dotenv
     * file or a secrets UI ate. All are operator mistakes, and none may reach
     * the user as anything more specific than "sign-in failed".
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'not a pem at all' => ['this is not a key'];
        yield 'truncated pem' => ["-----BEGIN PRIVATE KEY-----\nZm9v\n-----END PRIVATE KEY-----\n"];
        yield 'rsa instead of ec' => [self::rsaKey()];
        yield 'newlines flattened away' => [str_replace("\n", ' ', self::privateKey())];
    }

    #[DataProvider('unusableKeys')]
    public function testAnUnusableKeyFailsAsAGenericSignInFailure(string $privateKey): void
    {
        $factory = $this->factoryWith($privateKey, self::KEY_ID);

        // isConfigured() is a presence check, not a validity check: we cannot
        // parse the key without doing the work, and a deployment that pasted
        // garbage HAS configured Apple — badly. It stays visible and fails at
        // the exchange, which is the only place the failure is knowable.
        self::assertTrue($factory->isConfigured());

        try {
            $factory->create();
            self::fail('expected the signing failure to surface');
        } catch (OAuthFailedException $e) {
            // Byte-identical to what a token-endpoint timeout produces. The
            // cause survives only in $logDetail and $previous, neither of which
            // ApiExceptionListener can reach.
            self::assertSame('Sign-in failed', $e->title);
            self::assertSame('Signing in with that provider did not work. Please try again.', $e->detail);
            self::assertSame(502, $e->status);
        }
    }

    private function factory(MockClock $clock): AppleClientSecretFactory
    {
        return new AppleClientSecretFactory(
            $clock,
            self::SERVICES_ID,
            self::TEAM_ID,
            self::KEY_ID,
            self::privateKey(),
        );
    }

    private function factoryWith(string $privateKey, string $keyId): AppleClientSecretFactory
    {
        return new AppleClientSecretFactory(
            new MockClock('2026-07-21 12:00:00'),
            self::SERVICES_ID,
            self::TEAM_ID,
            $keyId,
            $privateKey,
        );
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private static function decodeSegments(string $jwt): array
    {
        $segments = explode('.', $jwt);
        self::assertCount(3, $segments);

        $decode = static function (string $segment): array {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(
                (string) base64_decode(strtr($segment, '-_', '+/'), true),
                true,
                512,
                \JSON_THROW_ON_ERROR,
            );

            return $decoded;
        };

        return [$decode($segments[0]), $decode($segments[1])];
    }

    private static function privateKey(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/oauth/apple-test-key.p8');
    }

    private static function publicKey(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/oauth/apple-test-key.pub.pem');
    }

    private static function rsaKey(): string
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $resource) {
            self::fail('could not generate a throwaway RSA key');
        }

        $pem = '';
        if (!openssl_pkey_export($resource, $pem) || !\is_string($pem)) {
            self::fail('could not export the throwaway RSA key');
        }

        return $pem;
    }
}
