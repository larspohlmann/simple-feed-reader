<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc\Pass;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Model\OAuthIdentityModel;
use App\Service\OAuth\Oidc\Model\IdTokenClaimsModel;
use App\Service\OAuth\Oidc\Model\IdTokenModel;
use Psr\Clock\ClockInterface;

/**
 * Verifies an ID token this application fetched itself. It skips the signature, which only {@see IdTokenModel}'s
 * origin permits, and checks every claim TLS says nothing about; each check fails with the same exception.
 * docs/oauth-sign-in.md#the-id-token-trust-boundary
 */
final readonly class IdTokenVerifier
{
    /** Tolerates clock drift on `exp`; a larger value would extend the life of a captured token. */
    private const int CLOCK_SKEW_SECONDS = 60;

    /**
     * @param list<string> $issuers accepted `iss` values
     */
    public function __construct(
        private ClockInterface $clock,
        private string $provider,
        private string $clientId,
        private array $issuers,
    ) {
    }

    public function verify(IdTokenModel $token, string $expectedNonce): OAuthIdentityModel
    {
        if ('' === $expectedNonce) {
            // An empty expectation would match a token carrying an empty nonce. AbstractOidcProvider::exchangeCode()
            // refuses it before the token call; this is the backstop.
            throw new OAuthFailedException('no nonce to check the id_token against');
        }

        $claims = IdTokenClaimsModel::decode($token);

        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertAuthorizedParty($claims);
        $this->assertNotExpired($claims);
        $this->assertNonce($claims, $expectedNonce);

        return $this->identityFrom($claims);
    }

    private function assertIssuer(IdTokenClaimsModel $claims): void
    {
        $issuer = $claims->string('iss');

        if (null === $issuer || !\in_array($issuer, $this->issuers, true)) {
            throw new OAuthFailedException('id_token issuer mismatch');
        }
    }

    private function assertAudience(IdTokenClaimsModel $claims): void
    {
        $mintedForUs = array_any(
            $claims->stringList('aud'),
            fn (string $audience): bool => hash_equals($this->clientId, $audience),
        );

        if (!$mintedForUs) {
            throw new OAuthFailedException('id_token audience mismatch');
        }
    }

    /**
     * OIDC Core §3.1.3.7 item 5: a present `azp` must name this client. Item 4's "require `azp` for several
     * audiences" is deliberately left out: docs/oauth-sign-in.md#the-id-token-trust-boundary
     */
    private function assertAuthorizedParty(IdTokenClaimsModel $claims): void
    {
        if (null === $claims->claim('azp')) {
            return;
        }

        $authorizedParty = $claims->string('azp');

        if (null === $authorizedParty || !hash_equals($this->clientId, $authorizedParty)) {
            throw new OAuthFailedException('id_token authorized party mismatch');
        }
    }

    private function assertNotExpired(IdTokenClaimsModel $claims): void
    {
        $expiry = $claims->int('exp');

        if (null === $expiry || $expiry + self::CLOCK_SKEW_SECONDS < $this->clock->now()->getTimestamp()) {
            throw new OAuthFailedException('id_token expired or has no exp');
        }
    }

    /** Ties the token to the flow this browser started; without it a token from anywhere could be replayed here. */
    private function assertNonce(IdTokenClaimsModel $claims, string $expectedNonce): void
    {
        $nonce = $claims->string('nonce');

        if (null === $nonce || !hash_equals($expectedNonce, $nonce)) {
            throw new OAuthFailedException('id_token nonce mismatch');
        }
    }

    private function identityFrom(IdTokenClaimsModel $claims): OAuthIdentityModel
    {
        $subject = $claims->string('sub');

        if (null === $subject || !self::isUsableSubject($subject)) {
            throw new OAuthFailedException('id_token carried no usable sub');
        }

        $email = $claims->string('email');

        return new OAuthIdentityModel(
            $this->provider,
            $subject,
            '' === $email ? null : $email,
            self::isVerified($claims->claim('email_verified')),
        );
    }

    /**
     * The subject is half of user_identity's unique key, stored byte for byte. Empty, padded or control-character
     * subjects are refused, never trimmed: each could put two provider accounts on one row, or one on two.
     */
    private static function isUsableSubject(string $subject): bool
    {
        return '' !== $subject
            && $subject === trim($subject)
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $subject);
    }

    /**
     * Only a JSON `true` (Google) or the string "true" (Apple) is verified; no cast, no case-folding. Reading
     * unverified as verified hands the address's existing account to whoever typed it, so anything else is unverified.
     */
    private static function isVerified(mixed $value): bool
    {
        return true === $value || 'true' === $value;
    }
}
