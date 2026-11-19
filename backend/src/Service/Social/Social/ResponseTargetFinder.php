<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\User;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\ResponseTargetModel;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\Model\SocialPostModel;

final readonly class ResponseTargetFinder
{
    public function __construct(
        private SocialPostFinder $posts,
        private ActingAccountChooser $actingAccounts,
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
        $address = $this->addressOf($target);

        return $this->posts->findAs($this->actingAccounts->forResponse($user, $target, $address->network), $address);
    }

    private function addressOf(ResponseTargetModel $target): SocialPostAddressModel
    {
        return null === $target->address
            ? $this->postAddresses->requireRespondablePost($target->entry)
            : $this->postAddresses->requireRespondableReply($target->entry, $target->address);
    }
}
