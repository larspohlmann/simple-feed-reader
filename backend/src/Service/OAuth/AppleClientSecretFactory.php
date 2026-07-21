<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Exception\OAuth\OAuthFailedException;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the thing Apple calls a "client secret" and everyone else calls a
 * signed assertion.
 *
 * Apple issues no static secret. Instead the relying party signs a short JWT
 * with the ES256 private key downloaded once from the developer portal, and
 * that JWT goes in the `client_secret` field of the token request.
 *
 * Two of the claims below are the reverse of what the generic OIDC
 * `private_key_jwt` profile would tell you to write, and getting them the usual
 * way round yields a token Apple rejects with a bare `invalid_client`:
 *
 * - `iss` is the **team** id, not the client id.
 * - `sub` is the **client** id (the Services ID).
 *
 * `kid` goes in the JOSE header, because a team may have several keys and Apple
 * has to know which public half to check the signature against.
 *
 * Apple caps the lifetime at six months. Ours is one hour, and the secret is
 * minted per request rather than cached: the signing operation is a single
 * ECDSA over a few hundred bytes — cheaper than the TLS handshake it rides
 * along with — so caching would trade a measurable nothing for a longer-lived
 * credential sitting in a cache file.
 */
final readonly class AppleClientSecretFactory
{
    private const AUDIENCE = 'https://appleid.apple.com';
    private const LIFETIME_SECONDS = 3600;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%env(APPLE_OAUTH_CLIENT_ID)%')] private string $servicesId,
        #[Autowire('%env(APPLE_OAUTH_TEAM_ID)%')] private string $teamId,
        #[Autowire('%env(APPLE_OAUTH_KEY_ID)%')] private string $keyId,
        #[Autowire('%env(APPLE_OAUTH_PRIVATE_KEY)%')] private string $privateKey,
    ) {
    }

    /**
     * All four values or none. A deployment that filled in three of them has
     * made a mistake, and the useful response to that mistake is to not offer
     * Apple at all — the alternative is a sign-in button that sends people to
     * Apple's consent screen and fails on the way back.
     *
     * Presence, not validity: whether the key actually parses cannot be known
     * without doing the signing work, so a deployment that pasted a malformed
     * key stays visible here and fails at the exchange instead. That is the
     * later failure, but it is the only honest one.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->servicesId
            && '' !== $this->teamId
            && '' !== $this->keyId
            && '' !== $this->privateKey;
    }

    public function create(): string
    {
        // Restates isConfigured() rather than calling it, for two reasons. It
        // gives the static analyser the non-empty-string it needs to prove the
        // builder calls below are well-typed, and it means a caller who reached
        // create() WITHOUT consulting isConfigured() — a future code path, a
        // test — gets the same generic sign-in failure as every other Apple
        // problem instead of an InvalidArgumentException from deep inside the
        // JWT library, which would escape as an opaque 500.
        if (
            '' === $this->servicesId
            || '' === $this->teamId
            || '' === $this->keyId
            || '' === $this->privateKey
        ) {
            throw new OAuthFailedException('apple oauth is not fully configured');
        }

        $now = $this->clock->now();

        try {
            return (new Builder(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates()))
                // withUnixTimestampDates(), NOT ChainedFormatter::default().
                //
                // The default formatter renders date claims through
                // MicrosecondBasedDateConversion, which emits a JSON float
                // (1784030400.123456) whenever the instant carries
                // microseconds. Apple wants NumericDate integers. Every clock
                // in the test suite is a MockClock sitting on a whole second,
                // where that formatter happens to emit an int — so the default
                // spelling passes every test here and fails only in
                // production, where NativeClock never lands on a whole second.
                ->withHeader('kid', $this->keyId)
                ->issuedBy($this->teamId)
                ->relatedTo($this->servicesId)
                ->permittedFor(self::AUDIENCE)
                ->issuedAt($now)
                ->expiresAt($now->add(new \DateInterval('PT' . self::LIFETIME_SECONDS . 'S')))
                ->getToken(new Sha256(), InMemory::plainText($this->privateKey))
                ->toString();
        } catch (\Throwable $e) {
            // A malformed .p8, a key of the wrong curve, a value whose newlines
            // a dotenv file or a secrets UI flattened. All are deployment
            // mistakes, and none may reach the user as anything but "sign-in
            // failed" — OAuthFailedException renders identically whatever went
            // wrong, so this cannot be used to probe how Apple is configured
            // here. The cause survives in $logDetail and $previous, which the
            // exception listener has no way to reach.
            throw new OAuthFailedException('apple client secret could not be signed', $e);
        }
    }
}
