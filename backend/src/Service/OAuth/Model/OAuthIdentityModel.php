<?php

declare(strict_types=1);

namespace App\Service\OAuth\Model;

use App\Entity\User;

/** One provider-verified identity from a completed code exchange: everything linking and sign-up decide from. */
final readonly class OAuthIdentityModel
{
    private const string PRIVATE_RELAY_DOMAIN = 'privaterelay.appleid.com';

    public ?string $email;

    /** A typed bool: converting a provider's raw "verified" claim is IdTokenVerifier's job alone. */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        ?string $email,
        public bool $emailVerified,
    ) {
        // Normalised like User::$email so linking compares like with like. A blank claim is no address at all:
        // an empty string would slip past isLinkableByEmail()'s null check.
        $normalized = null === $email ? null : User::normalizeEmail($email);

        $this->email = '' === $normalized ? null : $normalized;
    }

    /** Anchored on '@' and the end, so neither a subdomain nor a registrable lookalike counts as a relay address. */
    public function isPrivateRelay(): bool
    {
        if (null === $this->email) {
            return false;
        }

        return str_ends_with($this->email, '@' . self::PRIVATE_RELAY_DOMAIN);
    }

    /**
     * Links on a provider-VERIFIED address only: an unverified one would let anyone claim an existing account.
     * A private-relay address can never be one a human signed up with.
     */
    public function isLinkableByEmail(): bool
    {
        return null !== $this->email && $this->emailVerified && !$this->isPrivateRelay();
    }
}
