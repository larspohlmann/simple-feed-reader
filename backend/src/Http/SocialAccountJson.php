<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SocialAccount;

final readonly class SocialAccountJson
{
    /**
     * @param list<SocialAccount> $accounts
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function list(array $accounts): array
    {
        return ['accounts' => array_map($this->account(...), $accounts)];
    }

    /** @return array<string, mixed> */
    public function account(SocialAccount $account): array
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
        ];
    }
}
