<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

use App\Entity\User;

/**
 * One provider-verified identity, as returned from a completed code exchange.
 *
 * This is the entire surface a provider hands the application. Everything
 * downstream — linking, account creation, the JWT — is decided from these four
 * values, which is what keeps OAuthAccountLinker testable without a network.
 */
final readonly class OAuthIdentity
{
    /**
     * Apple mints one of these per (app, user) pair and forwards mail through
     * it. It is a real, verified, deliverable address that nonetheless can
     * never be an address a human typed into our signup form.
     */
    private const PRIVATE_RELAY_DOMAIN = 'privaterelay.appleid.com';

    public ?string $email;

    /**
     * $emailVerified is typed `bool`, and this file declares strict_types, so
     * a provider's `"true"` / `1` / `null` cannot reach it — the conversion is
     * AbstractOidcProvider's job, at the boundary where the raw claim is read.
     * Keeping the coercion out here means there is exactly one place that
     * decides what a provider's spelling of "verified" means.
     */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        ?string $email,
        public bool $emailVerified,
    ) {
        // Normalised at construction, through the same seam as User::$email,
        // so the linking comparison in OAuthAccountLinker cannot be defeated
        // by a provider that echoes back the capitalisation a user typed.
        //
        // A blank claim collapses to null rather than to '': "no address" must
        // have one representation, or isLinkableByEmail()'s null check has a
        // hole in it. User::__construct() rejects an empty address outright,
        // so no account can ever hold '' for a blank claim to match anyway.
        $normalized = null === $email ? null : User::normalizeEmail($email);

        $this->email = '' === $normalized ? null : $normalized;
    }

    /**
     * Matches the relay domain exactly, anchored on both sides: the '@' rules
     * out `sub.privaterelay.appleid.com` and `notprivaterelay.appleid.com`,
     * and the end-of-string rules out a registrable lookalike such as
     * `privaterelay.appleid.com.evil.test`. The address is already lowercased
     * by the constructor, so a plain comparison is case-insensitive.
     */
    public function isPrivateRelay(): bool
    {
        if (null === $this->email) {
            return false;
        }

        return str_ends_with($this->email, '@' . self::PRIVATE_RELAY_DOMAIN);
    }

    /**
     * Whether this identity's address may be used to claim an existing local
     * account.
     *
     * The `emailVerified` half is the security-critical one. A provider that
     * lets a user set an arbitrary unverified address on their profile would
     * otherwise be an account-takeover machine: sign up there as
     * admin@ourdomain, sign in here, get handed the real admin's account. We
     * link on provider-*verified* addresses only, and treat everything else as
     * a brand new signup.
     */
    public function isLinkableByEmail(): bool
    {
        return null !== $this->email && $this->emailVerified && !$this->isPrivateRelay();
    }
}
