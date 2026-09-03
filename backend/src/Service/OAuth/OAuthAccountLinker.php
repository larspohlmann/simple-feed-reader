<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\RegistrationMethod;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Service\Auth\RegistrationPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns a provider-verified identity into the local user it belongs to,
 * creating or linking as required.
 *
 * The only class in the OAuth stack that writes to the database, which keeps
 * every rule below testable without a network.
 *
 * The rules, in the order they are applied:
 *
 *  1. A UserIdentity row already matches (provider, sub): that is the account,
 *     full stop. Nothing about the address can change the answer.
 *  2. The identity's address is linkable — provider-verified and not a private
 *     relay — and an account holds it: link to that account.
 *  3. Otherwise: a brand new account, with no password, in pending_approval —
 *     or active immediately when the admin-approval toggle is off.
 *
 * Rule 1 before rule 2 means a returning user whose provider address has since
 * changed still lands on their own account instead of being linked onto whoever
 * holds the new address today.
 */
final readonly class OAuthAccountLinker
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserIdentityRepository $identities,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private RegistrationPolicy $policy,
    ) {
    }

    public function resolve(OAuthIdentity $identity): User
    {
        $existing = $this->identities->findOneByProviderAndSubject(
            $identity->provider,
            $identity->providerUserId,
        );

        if (null !== $existing) {
            return $this->refresh($existing, $identity);
        }

        $linkTarget = $this->findLinkTarget($identity);

        if (null === $linkTarget) {
            $user = $this->createUser($identity);
            $enteredApprovalQueue = $this->policy->approvalRequired();
        } else {
            $user = $linkTarget;
            $enteredApprovalQueue = $this->claimIfUnverified($linkTarget);
        }

        $this->attach($user, $identity);
        $this->em->flush();

        if ($enteredApprovalQueue) {
            // After the flush, for the same reason RegistrationService dispatches
            // after its own: the account is persisted in the queue before an
            // admin is told to look at it.
            $this->events->dispatch(new UserAwaitingApproval($user, RegistrationMethod::OAuth, $identity->provider));
        }

        return $user;
    }

    /**
     * A returning user. The identity's stored address is kept current so the
     * admin list shows what the provider reports today.
     *
     * Note what is NOT updated: User::$email. That is the login identifier and
     * the destination for password-reset mail; rewriting it from a provider
     * callback would let anyone who compromised a linked provider account
     * redirect this account's recovery mail to themselves. Changing a login
     * address is a deliberate, separately authenticated action, not a side effect
     * of signing in.
     */
    private function refresh(UserIdentity $existing, OAuthIdentity $identity): User
    {
        if ($identity->email !== $existing->getEmail()) {
            $existing->setEmail($identity->email);
            $this->em->flush();
        }

        return $existing->getUser();
    }

    /**
     * The linking rule. Returns the local account this identity may claim, or
     * null if it may claim none.
     */
    private function findLinkTarget(OAuthIdentity $identity): ?User
    {
        if (!$identity->isLinkableByEmail()) {
            return null;
        }

        \assert(null !== $identity->email);

        return $this->users->findOneByEmail($identity->email);
    }

    /**
     * An account that was registered with this address but never confirmed it.
     *
     * Whoever set that password never proved they can read mail at this address —
     * and the provider has just told us somebody else can. So the address changes
     * hands: the account is promoted out of the verification queue, and the
     * unproven password is discarded.
     *
     * How the owner gets back in: this method leaves the account in
     * `pending_approval`, and RegistrationService::requestPasswordReset() returns
     * silently for anything that is not `Active` or `Suspended`, so a reset is
     * possible only AFTER an admin approves. The immediate way in is the identity
     * that just claimed the row — the person who proved the address at the
     * provider signs in with that provider, which is this very request. Approval
     * first, reset second; nobody is stranded.
     *
     * That does not weaken the wipe: the discarded password belongs to someone who
     * never proved the address, and the party who DID prove it holds a working
     * sign-in the moment approval lands. Keeping the password to preserve a
     * recovery path would preserve it for the wrong person.
     *
     * Without this, an attacker could park an unverified registration on any
     * address and wait for its real owner to sign in with Google, at which point
     * the attacker's password would unlock the victim's account.
     *
     * setPasswordHash() also stamps passwordChangedAt, which invalidates any JWT
     * issued before now — so a session the attacker somehow holds dies here too.
     *
     * The alternative — refusing to link and creating a second account — was
     * rejected: it hands the attacker a cheap denial of service (its owner can
     * never reach the account that address names), and it strands the common
     * legitimate case, where the abandoned registration is the user's own.
     * Nothing is lost by claiming the row: an unverified account holds only an
     * address nobody has proven and a password nobody has used.
     *
     * When admin approval is off, the account is promoted straight to active
     * (approvedAt stamped) instead of into the queue, and the password is still
     * wiped — that wipe is a security control over an unproven credential, not a
     * step in the approval workflow, so the toggle has no say over it.
     *
     * Returns whether this call put the account into the approval queue, so
     * resolve() knows when a fresh approval is pending — false both when nothing
     * was claimed and when it was claimed but approval is off (the "no dispatch"
     * cases). Every status other than pending_verification is returned untouched:
     * OAuth proves an address, it does not overrule an admin, so linking never
     * revives a rejected account, never unsuspends a suspended one, and never
     * re-stamps an active account's password — that last would revoke the live
     * sessions of a user who did nothing but sign in a second way.
     */
    private function claimIfUnverified(User $user): bool
    {
        if (UserStatus::PendingVerification !== $user->getStatus()) {
            return false;
        }

        $now = $this->clock->now();
        if ($this->policy->approvalRequired()) {
            $user->setStatus(UserStatus::PendingApproval);
        } else {
            $user->setStatus(UserStatus::Active);
            $user->setApprovedAt($now);
        }
        $user->markEmailVerified($now);
        $user->setPasswordHash(null, $now);

        return $this->policy->approvalRequired();
    }

    /**
     * A first sign-in with no matching local account.
     *
     * Skips pending_verification unconditionally: the double opt-in mail proves
     * the address belongs to the person signing up, and the provider has already
     * proved that — regardless of the email-confirmation toggle, which governs
     * the local registration form, not an address OAuth already verified.
     *
     * Lands in pending_approval when admin approval is on — OAuth verifies
     * identity, humans decide access. When approval is off, the account is active
     * immediately and approvedAt is stamped.
     */
    private function createUser(OAuthIdentity $identity): User
    {
        $now = $this->clock->now();
        $user = new User($this->loginIdentifierFor($identity), $now);
        if ($identity->isLinkableByEmail()) {
            $user->markEmailVerified($now);
        }

        if ($this->policy->approvalRequired()) {
            $user->setStatus(UserStatus::PendingApproval);
        } else {
            $user->setStatus(UserStatus::Active);
            $user->setApprovedAt($now);
        }

        $this->em->persist($user);

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
     * Refusing to link an unverified address is only half the rule. Taking it as
     * the identifier anyway would let an attacker whose provider allows arbitrary
     * unverified addresses park `admin@company.example` in the approval queue —
     * approved on how the address reads — and then share that account with the
     * real owner, who can recover a password through the reset flow. So an
     * unlinkable claim is recorded on the UserIdentity row, visibly
     * provider-reported, never on the user.
     *
     * A private relay goes the same way for a different reason: it is a real
     * deliverable address, but it belongs to one (app, Apple user) pair rather
     * than a person, so no login should hang on it — and it may already be held
     * by a local account we just refused to link to.
     *
     * When the address IS linkable, findLinkTarget() has just established that no
     * account holds it. A concurrent request for the same address can still lose
     * that race and hit uniq_user_email; that surfaces as a 500 on a retryable
     * request, as RegistrationService does with the same race.
     */
    private function loginIdentifierFor(OAuthIdentity $identity): string
    {
        if ($identity->isLinkableByEmail()) {
            \assert(null !== $identity->email);

            return $identity->email;
        }

        return $this->placeholderEmail($identity);
    }

    /**
     * A synthetic, non-routable address for an identity that has none we may use
     * — Apple returns the address only on the FIRST authorisation, so a user who
     * revokes and re-authorises arrives with `sub` and nothing else, and
     * User::$email is non-nullable and unique.
     *
     * `.invalid` is reserved by RFC 2606 so it can never resolve, and is visibly
     * not a real address to the admin reviewing the queue.
     *
     * One path does try to deliver to it: approving such an account sends the
     * "you're in" mail, which AdminUserController addresses to User::$email. That
     * send is deferred to kernel.terminate and its failures are logged rather than
     * rethrown, so the bounce costs a log line and nothing else. Nothing else
     * reaches AccountMailer without an address a human typed: registration,
     * verification and password reset all start from one.
     *
     * Derived from provider and subject rather than random, so it is stable: the
     * same identity reconstructs the same placeholder instead of accumulating a
     * new account per sign-in. The hash also keeps the subject out of a column the
     * admin UI displays.
     */
    private function placeholderEmail(OAuthIdentity $identity): string
    {
        return \sprintf(
            '%s-%s@oauth.invalid',
            $identity->provider,
            substr(hash('sha256', $identity->providerUserId), 0, 32),
        );
    }

    private function attach(User $user, OAuthIdentity $identity): void
    {
        $link = new UserIdentity(
            $user,
            $identity->provider,
            $identity->providerUserId,
            $this->clock->now(),
        );
        $link->setEmail($identity->email);

        $this->em->persist($link);
    }
}
