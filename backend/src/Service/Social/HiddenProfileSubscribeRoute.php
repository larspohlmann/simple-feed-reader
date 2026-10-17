<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Exception\BlueskyProfileHiddenException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\Model\SubscribeOutcomeModel;
use App\Service\Subscription\Model\SubscribeRequestModel;
use App\Service\Subscription\SubscribeRoute\SubscribeRouteInterface;
use Psr\Log\LoggerInterface;

/** A bsky.app profile hidden from logged-out readers: its RSS answers 403, so it is read through the user's account. */
final readonly class HiddenProfileSubscribeRoute implements SubscribeRouteInterface
{
    private const string CONNECT_AN_ACCOUNT = 'This profile is only visible to logged-in Bluesky users. Connect a'
        . ' Bluesky account in Settings → Social Media to subscribe.';

    public function __construct(
        private HiddenProfilesInterface $hiddenProfiles,
        private SocialAccountRepository $socialAccounts,
        private AuthorFeeds $authorFeeds,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws BlueskyProfileHiddenException when the profile is hidden and no connected Bluesky account can read it */
    public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel
    {
        $actor = ProfileFeedUrl::blueskyProfileActorOf($request->url);
        $profile = null === $actor ? null : $this->hiddenProfileOf($actor);
        if (null === $profile) {
            return null;
        }
        $account = $this->socialAccounts->findReadyActingOnNetwork($request->user, SocialNetwork::Bluesky)
            ?? throw new BlueskyProfileHiddenException(self::CONNECT_AN_ACCOUNT);

        return SubscribeOutcomeModel::subscribed($this->authorFeeds->create($account, $profile, $request->tags));
    }

    private function hiddenProfileOf(string $actor): ?HiddenProfileModel
    {
        try {
            return $this->hiddenProfiles->hiddenAmong([$actor])[$actor] ?? null;
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Subscribe could not tell whether a Bluesky profile is hidden; discovery decides.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
