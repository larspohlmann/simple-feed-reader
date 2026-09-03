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
    private const string PRIVATE_RELAY_DOMAIN = 'privaterelay.appleid.com';

    public ?string $email;

    /**
     * Typed `bool` under strict_types, so a provider's `"true"` / `1` / `null`
     * cannot reach it — converting the raw claim is
     * {@see \App\Service\OAuth\Oidc\IdTokenVerifier}'s job, keeping one single
     * place that decides what a provider's "verified" means.
     */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        ?string $email,
        public bool $emailVerified,
    ) {
        // Normalised through the same seam as User::$email, so a provider
        // can't defeat OAuthAccountLinker's linking comparison by echoing
        // back the capitalisation a user typed.
        //
        // A blank claim collapses to null, not '': "no address" needs one
        // representation, or isLinkableByEmail()'s null check has a hole
        // (User::__construct() rejects '' outright anyway, so no account
        // could hold it for a blank claim to match).
        $normalized = null === $email ? null : User::normalizeEmail($email);

        $this->email = '' === $normalized ? null : $normalized;
    }

    /**
     * Anchored on both sides: the '@' rules out `sub.privaterelay.appleid.com`
     * and `notprivaterelay.appleid.com`; the end-of-string rules out a
     * registrable lookalike such as `privaterelay.appleid.com.evil.test`.
     * Already lowercased by the constructor, so a plain comparison is
     * case-insensitive.
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
     * `emailVerified` is the security-critical half: a provider that lets a
     * user set an arbitrary unverified profile address would otherwise be an
     * account-takeover machine (sign up there as admin@ourdomain, sign in
     * here, get handed the real admin's account). We link on
     * provider-*verified* addresses only; everything else is a brand new
     * signup.
     */
    public function isLinkableByEmail(): bool
    {
        return null !== $this->email && $this->emailVerified && !$this->isPrivateRelay();
    }
}
