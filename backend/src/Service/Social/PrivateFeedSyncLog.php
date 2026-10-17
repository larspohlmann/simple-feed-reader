<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Repository\PrivateFeedSyncChangeRepository;

final readonly class PrivateFeedSyncLog
{
    public function __construct(private PrivateFeedSyncChangeRepository $changes)
    {
    }

    /**
     * @param list<SocialAccount> $accounts
     *
     * @return array<int, list<PrivateFeedSyncChange>> newest first, by account id
     */
    public function recentOf(array $accounts): array
    {
        return $this->changes->recentOf($accounts);
    }

    /** @return list<PrivateFeedSyncChange> */
    public function recentOfOne(SocialAccount $account): array
    {
        return $this->recentOf([$account])[$account->requireId()] ?? [];
    }
}
