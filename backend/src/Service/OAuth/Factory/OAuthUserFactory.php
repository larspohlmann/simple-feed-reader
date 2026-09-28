<?php

declare(strict_types=1);

namespace App\Service\OAuth\Factory;

use App\Entity\User;
use App\Service\Auth\RegistrationPolicy;
use App\Service\OAuth\Model\OAuthIdentityModel;
use Psr\Clock\ClockInterface;

final readonly class OAuthUserFactory
{
    public function __construct(private ClockInterface $clock, private RegistrationPolicy $policy)
    {
    }

    /**
     * A first sign-in with no matching local account.
     *
     * Skips pending_verification unconditionally: the double opt-in mail
     * proves the address belongs to the person signing up, and the provider
     * has already proved that — regardless of the email-confirmation toggle,
     * which governs the local registration form, not an address OAuth already
     * verified.
     *
     * Lands in pending_approval when admin approval is on — OAuth verifies
     * identity, humans decide access. When approval is off, the account is
     * active immediately and approvedAt is stamped.
     */
    public function create(OAuthIdentityModel $identity): User
    {
        $now = $this->clock->now();
        $user = new User($this->loginIdentifierFor($identity), $now);
        if ($identity->isLinkableByEmail()) {
            $user->markEmailVerified($now);
        }

        if ($this->policy->approvalRequired()) {
            $user->queueForApproval();
        } else {
            $user->approve($now);
        }

        return $user;
    }

    /**
     * What goes in User::$email — the login identifier — for a new account.
     *
     * Only an address this identity was allowed to LINK on may become one:
     * isLinkableByEmail() is the same gate, used twice on purpose, so the
     * invariant reads "User::$email is only ever an address somebody proved
     * they own".
     *
     * Refusing to link an unverified address is only half the rule. Taking it
     * as the identifier anyway would let an attacker whose provider allows
     * arbitrary unverified addresses park `admin@company.example` in the
     * approval queue — approved on how the address reads — then share that
     * account with the real owner, who can recover a password through the
     * reset flow. So an unlinkable claim is recorded on the UserIdentity row,
     * visibly provider-reported, never on the user.
     *
     * A private relay goes the same way for a different reason: it is a real
     * deliverable address, but belongs to one (app, Apple user) pair rather
     * than a person, so no login should hang on it — and it may already be
     * held by a local account we just refused to link to.
     *
     * When the address IS linkable, OAuthAccountLinker::findLinkTarget() has
     * just established that no account holds it. A concurrent request for the
     * same address can still lose that race and hit uniq_user_email,
     * surfacing as a 500 on a retryable request, as RegistrationService does
     * with the same race.
     */
    private function loginIdentifierFor(OAuthIdentityModel $identity): string
    {
        if ($identity->isLinkableByEmail()) {
            \assert(null !== $identity->email);

            return $identity->email;
        }

        return $this->placeholderEmail($identity);
    }

    /**
     * A synthetic, non-routable address for an identity that has none we may
     * use — Apple returns the address only on the FIRST authorisation, so a
     * user who revokes and re-authorises arrives with `sub` and nothing else,
     * and User::$email is non-nullable and unique.
     *
     * `.invalid` is reserved by RFC 2606 so it can never resolve, and reads
     * visibly as not-a-real-address to the admin reviewing the queue.
     *
     * One path does try to deliver to it: approving such an account sends the
     * "you're in" mail, which AdminUserController addresses to User::$email.
     * That send is deferred to kernel.terminate and its failures are logged
     * rather than rethrown, so the bounce costs a log line and nothing else.
     * Nothing else reaches AccountMailer without an address a human typed:
     * registration, verification and password reset all start from one.
     *
     * Derived from provider and subject rather than random, so it is stable:
     * the same identity reconstructs the same placeholder instead of
     * accumulating a new account per sign-in. The hash also keeps the subject
     * out of a column the admin UI displays.
     */
    private function placeholderEmail(OAuthIdentityModel $identity): string
    {
        return \sprintf(
            '%s-%s@oauth.invalid',
            $identity->provider,
            substr(hash('sha256', $identity->providerUserId), 0, 32),
        );
    }
}
