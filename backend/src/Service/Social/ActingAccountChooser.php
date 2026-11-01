<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialFeedRepository;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\Support\SocialFeedClassifier;

/** Which of the user's accounts acts: the one chosen, else a private feed's own account or the network's default. */
final readonly class ActingAccountChooser
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SocialAccountRepository $socialAccounts,
        private SocialAccountForUser $chosenAccounts,
    ) {
    }

    /**
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException when the chosen account is not the user's, or on another network
     * @throws SocialAccountNeedsReconnectException
     */
    public function chosenOrDefault(User $user, ?int $accountId, SocialNetwork $network): SocialAccount
    {
        if (null !== $accountId) {
            return $this->chosenAccounts->requireConnectedOn($user, $accountId, $network);
        }

        return $this->socialAccounts->findReadyActingOnNetwork($user, $network)
            ?? throw new NoSocialAccountForNetworkException(
                sprintf('No %s account is connected.', $network->displayName()),
            );
    }

    /**
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException when the chosen account is not the user's, or on another network
     * @throws SocialAccountNeedsReconnectException
     */
    public function forResponse(User $user, ResponseTargetModel $target, SocialNetwork $network): SocialAccount
    {
        return null === $target->accountId
            ? $this->accountFor($user, $target->entry, $network)
            : $this->chosenAccounts->requireConnectedOn($user, $target->accountId, $network);
    }

    /** @throws NoSocialAccountForNetworkException */
    public function accountFor(User $user, Entry $entry, SocialNetwork $network): SocialAccount
    {
        return $this->accountOf($user, $entry->getFeed(), $network)
            ?? throw new NoSocialAccountForNetworkException(
                sprintf('No %s account is connected to act on this post.', $network->displayName()),
            );
    }

    private function accountOf(User $user, Feed $feed, SocialNetwork $network): ?SocialAccount
    {
        if (null === SocialFeedClassifier::privateAddressOf($feed)) {
            return $this->socialAccounts->findActingOnNetwork($user, $network);
        }
        $account = $this->socialFeeds->forFeed($feed)?->getSocialAccount();

        return $account?->getUser()->requireId() === $user->requireId() ? $account : null;
    }
}
