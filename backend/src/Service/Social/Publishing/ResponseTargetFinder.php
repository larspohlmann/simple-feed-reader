<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Publishing\Model\ResponseTargetModel;
use App\Service\Social\Social\ActingAccountChooser;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\SocialAccountForUser;
use App\Service\Social\Social\SocialPostAddresses;
use App\Service\Social\Social\SocialPostFinder;

final readonly class ResponseTargetFinder
{
    public function __construct(
        private SocialPostFinder $posts,
        private ActingAccountChooser $actingAccounts,
        private SocialAccountForUser $chosenAccounts,
        private SocialPostAddresses $postAddresses,
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
    public function find(User $user, ResponseTargetModel $target): SocialPostModel
    {
        $address = $this->requireAddress($target);

        return $this->posts->findAs($this->requireActingAccount($user, $target, $address->network), $address);
    }

    /**
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException when the chosen account is not the user's, or on another network
     * @throws SocialAccountNeedsReconnectException
     */
    private function requireActingAccount(
        User $user,
        ResponseTargetModel $target,
        SocialNetwork $network,
    ): SocialAccount {
        return null === $target->accountId
            ? $this->actingAccounts->requireAccount($user, $target->entry, $network)
            : $this->chosenAccounts->requireConnectedOn($user, $target->accountId, $network);
    }

    private function requireAddress(ResponseTargetModel $target): SocialPostAddressModel
    {
        return null === $target->address
            ? $this->postAddresses->requireRespondablePost($target->entry)
            : $this->postAddresses->requireRespondableReply($target->entry, $target->address);
    }
}
