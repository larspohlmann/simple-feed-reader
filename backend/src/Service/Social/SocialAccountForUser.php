<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Exception\SocialAccountNotFoundException;

/** Another account's row reads as missing (404, not 403), so a caller never learns an id exists. */
final readonly class SocialAccountForUser
{
    public function __construct(private SocialAccountRepository $socialAccounts)
    {
    }

    /** @throws SocialAccountNotFoundException */
    public function require(User $user, int $accountId): SocialAccount
    {
        return $this->socialAccounts->findOneForUser($user, $accountId)
            ?? throw new SocialAccountNotFoundException(sprintf('No social account %d for this account.', $accountId));
    }
}
