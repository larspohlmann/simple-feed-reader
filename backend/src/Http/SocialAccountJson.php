<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;

final readonly class SocialAccountJson
{
    /**
     * @param list<SocialAccount> $accounts
     * @param array<int, list<PrivateFeedSyncChange>> $recentChanges by account id
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function list(array $accounts, array $recentChanges): array
    {
        return [
            'accounts' => array_map(
                fn (SocialAccount $account): array => $this->account($account, $recentChanges),
                $accounts,
            ),
        ];
    }

    /**
     * @param array<int, list<PrivateFeedSyncChange>> $recentChanges by account id
     *
     * @return array<string, mixed>
     */
    public function account(SocialAccount $account, array $recentChanges): array
    {
        return [
            'id' => $account->requireId(),
            'network' => $account->getNetwork()->value,
            'handle' => $account->getHandle(),
            'serviceUrl' => $account->getServiceUrl(),
            'status' => $account->getStatus()->value,
            'isDefault' => $account->isDefault(),
            'credentialHint' => $account->getCredentialHint(),
            'connectedAt' => $account->getConnectedAt()->format(\DateTimeInterface::ATOM),
            'sync' => $this->syncOf($account, $recentChanges[$account->requireId()] ?? []),
        ];
    }

    /**
     * @param list<PrivateFeedSyncChange> $recentChanges
     *
     * @return array{checkedAt: string|null, skipped: string|null, changes: list<array<string, string>>}
     */
    private function syncOf(SocialAccount $account, array $recentChanges): array
    {
        return [
            'checkedAt' => $account->getLastCheckedAt()?->format(\DateTimeInterface::ATOM),
            'skipped' => $account->getLastSyncSkip()?->value,
            'changes' => array_map(
                static fn (PrivateFeedSyncChange $change): array => [
                    'kind' => $change->getKind()->value,
                    'name' => $change->getName(),
                    'reason' => $change->getReason()->value,
                    'at' => $change->getChangedAt()->format(\DateTimeInterface::ATOM),
                ],
                $recentChanges,
            ),
        ];
    }
}
