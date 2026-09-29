<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\RegistrationMethod;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Service\Auth\RegistrationPolicy;
use App\Service\OAuth\Factory\OAuthUserFactory;
use App\Service\OAuth\Model\OAuthIdentityModel;
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
 *  2. The identity's address is linkable — provider-verified and not a
 *     private relay — and an account holds it: link to that account.
 *  3. Otherwise: a brand new account, with no password, in pending_approval —
 *     or active immediately when the admin-approval toggle is off.
 *
 * Rule 1 before rule 2: a returning user whose provider address has since
 * changed still lands on their own account, not on whoever holds the new
 * address today.
 */
final readonly class OAuthAccountLinker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private UserIdentityRepository $identities,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private RegistrationPolicy $policy,
        private OAuthUserFactory $userFactory,
    ) {
    }

    public function resolve(OAuthIdentityModel $identity): User
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
            $user = $this->userFactory->create($identity);
            $this->entityManager->persist($user);
            $enteredApprovalQueue = $this->policy->approvalRequired();
        } else {
            $user = $linkTarget;
            $enteredApprovalQueue = $this->claimIfUnverified($linkTarget);
        }

        $this->attach($user, $identity);
        $this->entityManager->flush();

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
     * Note what is NOT updated: User::$email, the login identifier and the
     * destination for password-reset mail. Rewriting it from a provider
     * callback would let anyone who compromised a linked provider account
     * redirect this account's recovery mail to themselves — changing a login
     * address must stay a deliberate, separately authenticated action, not a
     * side effect of signing in.
     */
    private function refresh(UserIdentity $existing, OAuthIdentityModel $identity): User
    {
        if ($identity->email !== $existing->getEmail()) {
            $existing->setEmail($identity->email);
            $this->entityManager->flush();
        }

        return $existing->getUser();
    }

    /**
     * The linking rule. Returns the local account this identity may claim, or
     * null if it may claim none.
     */
    private function findLinkTarget(OAuthIdentityModel $identity): ?User
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
     * Whoever set that password never proved they can read mail at this
     * address, and the provider has just told us somebody else can. So the
     * address changes hands: promoted out of the verification queue, unproven
     * password discarded.
     *
     * How the owner gets back in: this leaves the account in
     * `pending_approval`, and RegistrationService::requestPasswordReset()
     * returns silently for anything that is not `Active` or `Suspended`, so a
     * reset is possible only AFTER an admin approves. The immediate way in is
     * the identity that just claimed the row signing in again with that
     * provider. Approval first, reset second; nobody is stranded.
     *
     * That does not weaken the wipe: the discarded password belongs to
     * someone who never proved the address, while whoever DID prove it holds
     * a working sign-in the moment approval lands — keeping the password for
     * a recovery path would preserve it for the wrong person. Without this,
     * an attacker could park an unverified registration on any address and
     * wait for its real owner to sign in with Google, at which point the
     * attacker's password would unlock the victim's account. setPasswordHash()
     * also stamps passwordChangedAt, invalidating any JWT issued before now,
     * so a session the attacker somehow holds dies here too.
     *
     * The alternative — refuse to link, create a second account — was
     * rejected: it hands the attacker a cheap denial of service (the real
     * owner can never reach the account that address names) and strands the
     * common legitimate case, where the abandoned registration is the user's
     * own. Nothing is lost by claiming the row: an unverified account holds
     * only an unproven address and an unused password.
     *
     * When admin approval is off, the account is promoted straight to active
     * (approvedAt stamped) instead of into the queue, but the password is
     * still wiped — a security control over an unproven credential, not a
     * step in the approval workflow, so the toggle has no say over it.
     *
     * Returns whether this call put the account into the approval queue, so
     * resolve() knows when a fresh approval is pending — false both when
     * nothing was claimed and when it was claimed but approval is off. Every
     * status other than pending_verification is returned untouched: OAuth
     * proves an address, it does not overrule an admin, so linking never
     * revives a rejected account, never unsuspends a suspended one, and never
     * re-stamps an active account's password — that would revoke the live
     * sessions of a user who did nothing but sign in a second way.
     */
    private function claimIfUnverified(User $user): bool
    {
        if (UserStatus::PendingVerification !== $user->getStatus()) {
            return false;
        }

        $now = $this->clock->now();
        if ($this->policy->approvalRequired()) {
            $user->queueForApproval();
        } else {
            $user->approve($now);
        }
        $user->markEmailVerified($now);
        $user->setPasswordHash(null, $now);

        return $this->policy->approvalRequired();
    }

    private function attach(User $user, OAuthIdentityModel $identity): void
    {
        $link = new UserIdentity(
            $user,
            $identity->provider,
            $identity->providerUserId,
            $this->clock->now(),
        );
        $link->setEmail($identity->email);

        $this->entityManager->persist($link);
    }
}
