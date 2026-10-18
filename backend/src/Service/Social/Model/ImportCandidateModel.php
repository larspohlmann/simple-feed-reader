<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

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

    public static function noLongerFollowed(ProfileFeedSubscriptionModel $profileFeed): self
    {
        return new self(
            new FollowedAccountModel(
                $profileFeed->identity,
                $profileFeed->handle(),
                $profileFeed->title,
                $profileFeed->feedUrl,
                $profileFeed->imageUrl,
            ),
            ImportCandidateState::NoLongerFollowed,
            $profileFeed->subscriptionId,
        );
    }

    public function asSubscribed(): self
    {
        return new self($this->account, ImportCandidateState::Subscribed, $this->subscriptionId);
    }
}
