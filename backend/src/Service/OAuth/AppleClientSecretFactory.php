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
 * Apple issues no static secret: the relying party signs a short JWT with the
 * ES256 private key downloaded once from the developer portal, and that JWT goes
 * in the `client_secret` field of the token request.
 *
 * Two claims are the reverse of the generic OIDC `private_key_jwt` profile;
 * getting them the usual way round yields a token Apple rejects with a bare
 * `invalid_client`:
 *
 * - `iss` is the **team** id, not the client id.
 * - `sub` is the **client** id (the Services ID).
 *
 * `kid` goes in the JOSE header, because a team may have several keys and Apple
 * must know which public half checks the signature.
 *
 * Apple caps the lifetime at six months; ours is one hour, minted per request
 * rather than cached: the signing is a single ECDSA over a few hundred bytes,
 * cheaper than the TLS handshake it rides with, so caching would trade nothing
 * for a longer-lived credential sitting in a cache file.
 */
final readonly class AppleClientSecretFactory
{
    private const string AUDIENCE = 'https://appleid.apple.com';
    private const int LIFETIME_SECONDS = 3600;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%env(APPLE_OAUTH_CLIENT_ID)%')] private string $servicesId,
        #[Autowire('%env(APPLE_OAUTH_TEAM_ID)%')] private string $teamId,
        #[Autowire('%env(APPLE_OAUTH_KEY_ID)%')] private string $keyId,
        #[Autowire('%env(APPLE_OAUTH_PRIVATE_KEY)%')] private string $privateKey,
    ) {
    }

    /**
     * All four values or none. A deployment with three of them has made a
     * mistake, and the useful response is to not offer Apple at all rather than
     * a sign-in button that sends people to Apple's consent screen and fails on
     * the way back.
     *
     * Presence, not validity: whether the key parses cannot be known without the
     * signing work, so a malformed key stays visible here and fails at the
     * exchange instead — the later failure, but the only honest one.
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
        // Restates isConfigured() rather than calling it, for two reasons: it
        // gives the static analyser the non-empty-string it needs to type the
        // builder calls, and a caller who reached create() WITHOUT isConfigured()
        // gets the same generic sign-in failure as every other Apple problem,
        // not an InvalidArgumentException from deep in the JWT library escaping
        // as an opaque 500.
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
            // Outer parens for PDepend 2.16.2 (composer md), which cannot parse
            // the PHP 8.4 "new without parentheses" chain yet — keep them. See #183.
            return (new Builder(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates()))
                // withUnixTimestampDates(), NOT ChainedFormatter::default().
                //
                // The default formatter renders date claims through
                // MicrosecondBasedDateConversion, which emits a JSON float
                // (1784030400.123456) when the instant carries microseconds;
                // Apple wants NumericDate integers. Test clocks are MockClocks on
                // a whole second, where that formatter emits an int — so the
                // default spelling passes every test and fails only in
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
            // A malformed .p8, a key of the wrong curve, newlines a dotenv file
            // or secrets UI flattened — all deployment mistakes, none of which
            // may reach the user as anything but "sign-in failed".
            // OAuthFailedException renders identically whatever went wrong, so it
            // cannot be used to probe how Apple is configured here. The cause
            // survives in $logDetail and $previous.
            throw new OAuthFailedException('apple client secret could not be signed', $e);
        }
    }
}
