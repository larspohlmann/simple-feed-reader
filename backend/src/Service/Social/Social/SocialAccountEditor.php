<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Event\SocialAccountDisconnected;
use App\Repository\SocialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class SocialAccountEditor
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private PrivateFeeds $privateFeeds,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $events,
    ) {
    }

    /** @return list<SocialAccount> */
    public function findAccounts(User $user): array
    {
        return $this->socialAccounts->findAllForUser($user);
    }

    public function makeDefault(SocialAccount $account): void
    {
        foreach ($this->findSiblings($account) as $sibling) {
            $sibling->stopBeingDefault();
        }
        $account->becomeDefault();
        $this->entityManager->flush();
    }

    public function disconnect(SocialAccount $account): void
    {
        $successor = $account->isDefault() ? ($this->findSiblings($account)[0] ?? null) : null;
        $this->events->dispatch(new SocialAccountDisconnected($account));

        $this->entityManager->wrapInTransaction(function () use ($account, $successor): void {
            $this->privateFeeds->unsubscribeAllFeeds($account);
            $this->entityManager->remove($account);
            $successor?->becomeDefault();
            $this->entityManager->flush();
        });
    }

    /** @return list<SocialAccount> the user's other accounts on the same network, oldest first */
    private function findSiblings(SocialAccount $account): array
    {
        $onNetwork = $this->socialAccounts->findAllOnNetwork($account->getUser(), $account->getNetwork());

        return array_values(array_filter(
            $onNetwork,
            static fn (SocialAccount $sibling): bool => $sibling !== $account,
        ));
    }
}
