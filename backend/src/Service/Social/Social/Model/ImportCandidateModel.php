<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Enum\SocialNetwork;

final readonly class ImportCandidateModel
{
    private function __construct(
        public FollowedAccountModel $account,
        public ImportCandidateState $state,
        public ?int $subscriptionId,
    ) {
    }

    public static function notYetSubscribed(FollowedAccountModel $follow): self
    {
        return new self($follow, ImportCandidateState::New, null);
    }

    public static function subscribed(FollowedAccountModel $follow, int $subscriptionId): self
    {
        return new self($follow, ImportCandidateState::Subscribed, $subscriptionId);
    }

    public static function noLongerFollowed(ProfileFeedSubscriptionModel $profileFeed, SocialNetwork $network): self
    {
        return new self(
            new FollowedAccountModel(
                $profileFeed->identity,
                $profileFeed->handleOn($network),
                $profileFeed->title,
                $profileFeed->feedUrl,
                $profileFeed->imageUrl,
            ),
            ImportCandidateState::NoLongerFollowed,
            $profileFeed->subscriptionId,
        );
    }

    #[\NoDiscard]
    public function asSubscribed(): self
    {
        return clone($this, ['state' => ImportCandidateState::Subscribed]);
    }
}
