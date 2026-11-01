<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ReplyTargetModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;

final readonly class ReplyTargetFinder
{
    public function __construct(
        private SocialPostFinder $posts,
        private ActingAccountChooser $actingAccounts,
        private SocialAccountForUser $chosenAccounts,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException when the chosen account is not the user's, or on another network
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function find(User $user, ReplyTargetModel $target): SocialPostModel
    {
        $address = self::addressOf($target);
        $account = null === $target->accountId
            ? $this->actingAccounts->accountFor($user, $target->entry, $address->network)
            : $this->chosen($user, $target->accountId, $address->network);

        return $this->posts->findAs($account, $address);
    }

    private function chosen(User $user, int $accountId, SocialNetwork $network): SocialAccount
    {
        $account = $this->chosenAccounts->requireConnected($user, $accountId);
        if ($network !== $account->getNetwork()) {
            throw new SocialAccountNotFoundException(
                sprintf('No %s account %d for this account.', $network->value, $accountId),
            );
        }

        return $account;
    }

    private static function addressOf(ReplyTargetModel $target): SocialPostAddressModel
    {
        return null === $target->address
            ? SocialPostAddress::ofOrRefuse($target->entry)
            : SocialPostAddress::replyOrRefuse($target->entry, $target->address);
    }
}
