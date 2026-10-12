<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialAccountEditor
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<SocialAccount> */
    public function accountsOf(User $user): array
    {
        return $this->socialAccounts->findAllForUser($user);
    }

    public function makeDefault(SocialAccount $account): void
    {
        foreach ($this->siblingsOf($account) as $sibling) {
            $sibling->stopBeingDefault();
        }
        $account->becomeDefault();
        $this->entityManager->flush();
    }

    public function disconnect(SocialAccount $account): void
    {
        $successor = $account->isDefault() ? ($this->siblingsOf($account)[0] ?? null) : null;

        $this->entityManager->remove($account);
        $successor?->becomeDefault();
        $this->entityManager->flush();
    }

    /** @return list<SocialAccount> the user's other accounts on the same network, oldest first */
    private function siblingsOf(SocialAccount $account): array
    {
        $onNetwork = $this->socialAccounts->findAllOnNetwork($account->getUser(), $account->getNetwork());

        return array_values(array_filter(
            $onNetwork,
            static fn (SocialAccount $sibling): bool => $sibling !== $account,
        ));
    }
}
