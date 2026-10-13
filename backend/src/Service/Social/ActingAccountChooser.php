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
use App\Service\Social\Support\SocialFeedClassifier;

/** Which of the user's accounts acts on an entry's post: a private feed's own account, else the network's default. */
final readonly class ActingAccountChooser
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SocialAccountRepository $socialAccounts,
    ) {
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
        $account = $this->socialFeeds->findOneByFeed($feed)?->getSocialAccount();

        return $account?->getUser()->requireId() === $user->requireId() ? $account : null;
    }
}
