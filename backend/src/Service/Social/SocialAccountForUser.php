<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
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

    /**
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     */
    public function requireConnected(User $user, int $accountId): SocialAccount
    {
        $account = $this->require($user, $accountId);
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            throw new SocialAccountNeedsReconnectException(
                sprintf('%s needs reconnecting before it can be read.', $account->getHandle()),
            );
        }

        return $account;
    }

    /**
     * @throws SocialAccountNotFoundException when the account is not the user's, or on another network
     * @throws SocialAccountNeedsReconnectException
     */
    public function requireConnectedOn(User $user, int $accountId, SocialNetwork $network): SocialAccount
    {
        $account = $this->requireConnected($user, $accountId);
        if ($network !== $account->getNetwork()) {
            throw new SocialAccountNotFoundException(
                sprintf('No %s account %d for this account.', $network->value, $accountId),
            );
        }

        return $account;
    }
}
