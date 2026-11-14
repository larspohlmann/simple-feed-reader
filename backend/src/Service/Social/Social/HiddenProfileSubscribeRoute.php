<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Social\Exception\BlueskyProfileHiddenException;
use App\Service\Library\Subscription\Exception\AlreadySubscribedException;
use App\Service\Library\Subscription\Model\SubscribeOutcomeModel;
use App\Service\Library\Subscription\Model\SubscribeRequestModel;
use App\Service\Library\Subscription\SubscribeRoute\SubscribeRouteInterface;

final readonly class HiddenProfileSubscribeRoute implements SubscribeRouteInterface
{
    public const string CONNECT_AN_ACCOUNT = 'This profile is only visible to logged-in Bluesky users. Connect a'
        . ' Bluesky account in Settings → Social Media to subscribe.';

    public function __construct(
        private TolerantHiddenProfiles $hiddenProfiles,
        private SocialAccountRepository $socialAccounts,
        private PrivateFeeds $privateFeeds,
    ) {
    }

    /**
     * @throws BlueskyProfileHiddenException when the profile is hidden and no connected Bluesky account can read it
     * @throws AlreadySubscribedException
     */
    public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel
    {
        $profile = $this->hiddenProfiles->hiddenAt(
            $request->url,
            'Subscribe could not tell whether a Bluesky profile is hidden; discovery decides.',
        );
        if (null === $profile) {
            return null;
        }
        $account = $this->socialAccounts->findReadyActingOnNetwork($request->user, SocialNetwork::Bluesky)
            ?? throw new BlueskyProfileHiddenException(self::CONNECT_AN_ACCOUNT);

        $author = $profile->toAuthorFeed();
        if (null !== $this->privateFeeds->subscriptionOf($account, $author)) {
            throw new AlreadySubscribedException();
        }

        return SubscribeOutcomeModel::subscribed($this->privateFeeds->subscribe($account, $author, $request->tags));
    }
}
