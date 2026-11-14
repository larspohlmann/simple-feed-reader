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
 * Finds or creates the local user for a provider-verified identity: a known (provider, sub) wins over any address,
 * then a verified, non-relay address links, else a new account. docs/security.md#oauth-account-linking
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
     * Updates the identity's address, never User::$email: a compromised provider account must not be able to
     * redirect this account's password-reset mail.
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
     * Claims a pending_verification account for the address the provider just proved, wiping and stamping its
     * unproven password whatever the approval toggle; any other status stays untouched. Returns whether it queued
     * the account for approval. docs/security.md#oauth-account-linking
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
