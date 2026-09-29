<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Factory;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Factory\AppleClientSecretFactory;
use App\Tests\Support\AppleTestKey;
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

        // Apple inverts the private_key_jwt shape: `iss` is the team, `sub` the Services ID (Apple's "Creating a
        // client secret" documentation).
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
     * ChainedFormatter::default() emits float dates once the instant carries microseconds, which NativeClock always
     * does and MockClock never; this keeps withUnixTimestampDates() from being "simplified" back.
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
     * Any one of the four missing means Apple is not offered at all, rather than offered and failing at the exchange.
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
     * Not a key, the wrong curve, and a PEM whose newlines were eaten: each must reach the user as "sign-in failed".
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

        // Presence, not validity: a garbage key counts as configured and fails at the exchange.
        self::assertTrue($factory->isConfigured());

        try {
            $factory->create();
            self::fail('expected the signing failure to surface');
        } catch (OAuthFailedException $exception) {
            self::assertSame('apple client secret could not be signed', $exception->logDetail);
            self::assertNotNull($exception->getPrevious());
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
        return AppleTestKey::privateKey();
    }

    private static function publicKey(): string
    {
        return AppleTestKey::publicKey();
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
