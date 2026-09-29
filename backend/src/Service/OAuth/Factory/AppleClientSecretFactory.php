<?php

declare(strict_types=1);

namespace App\Service\OAuth\Factory;

use App\Service\OAuth\Exception\OAuthFailedException;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs the ES256 JWT Apple takes as a client secret: `iss` is the team id and `sub` the Services ID, the reverse of
 * OIDC's `private_key_jwt`. One hour, minted per exchange and never cached.
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
     * All four values or none, so a half-configured Apple is never offered. Presence only: a malformed key fails at
     * the exchange.
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
                // withUnixTimestampDates(), not default(): the default emits float dates once the clock carries
                // microseconds, which only the production clock does, and Apple wants integers.
                ->withHeader('kid', $this->keyId)
                ->issuedBy($this->teamId)
                ->relatedTo($this->servicesId)
                ->permittedFor(self::AUDIENCE)
                ->issuedAt($now)
                ->expiresAt($now->add(new \DateInterval('PT' . self::LIFETIME_SECONDS . 'S')))
                ->getToken(new Sha256(), InMemory::plainText($this->privateKey))
                ->toString();
        } catch (\Throwable $exception) {
            // Every broken-key cause reaches the user as the one generic sign-in failure; the cause stays in the log.
            throw new OAuthFailedException('apple client secret could not be signed', $exception);
        }
    }
}
