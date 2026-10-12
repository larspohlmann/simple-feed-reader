<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\SocialAccount;

final class ThrottledSocialAccounts
{
    /** @var array<int, true> */
    private array $accountIds = [];

    public function add(SocialAccount $account): void
    {
        $this->accountIds[$account->requireId()] = true;
    }

    public function contains(SocialAccount $account): bool
    {
        return isset($this->accountIds[$account->requireId()]);
    }
}
