<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Support\ProfileFeedUrl;

/** One picker load's matching: each follow answers to its identity, its handle and its own feed's key. */
final class FollowMatching
{
    /** @var list<FollowedAccountModel> */
    private readonly array $follows;

    /** @var array<string, int> lower-cased key => follow position */
    private readonly array $followByKey;

    /** @var array<int, int> follow position => subscription id */
    private array $subscriptionOfFollow = [];

    /** @var array<int, ProfileFeedSubscriptionModel> by subscription id */
    private array $unmatched = [];

    /** @param list<FollowedAccountModel> $follows */
    public function __construct(
        private readonly SocialNetwork $network,
        array $follows,
    ) {
        $this->follows = self::firstOfEachIdentity($follows);
        $this->followByKey = $this->indexFollows();
    }

    public function offer(ProfileFeedSubscriptionModel $profileFeed): void
    {
        if (!$this->claim($profileFeed, $profileFeed->identity)) {
            $this->unmatched[$profileFeed->subscriptionId] = $profileFeed;
        }
    }

    /** @return list<ProfileFeedSubscriptionModel> leftover Bluesky feeds keyed by a handle rather than a DID */
    public function unmatchedHandles(): array
    {
        if (SocialNetwork::Bluesky !== $this->network) {
            return [];
        }

        return array_values(array_filter(
            $this->unmatched,
            static fn (ProfileFeedSubscriptionModel $profileFeed): bool
                => !str_starts_with($profileFeed->identity, 'did:'),
        ));
    }

    public function claimAs(ProfileFeedSubscriptionModel $profileFeed, string $did): void
    {
        if ($this->claim($profileFeed, $did)) {
            unset($this->unmatched[$profileFeed->subscriptionId]);
        }
    }

    /** @return list<ImportCandidateModel> */
    public function candidates(): array
    {
        $candidates = [];
        foreach ($this->follows as $position => $follow) {
            $subscriptionId = $this->subscriptionOfFollow[$position] ?? null;
            $candidates[] = null === $subscriptionId
                ? ImportCandidateModel::notYetSubscribed($follow)
                : ImportCandidateModel::subscribed($follow, $subscriptionId);
        }
        foreach ($this->unmatched as $profileFeed) {
            $candidates[] = ImportCandidateModel::noLongerFollowed($profileFeed);
        }

        return $candidates;
    }

    private function claim(ProfileFeedSubscriptionModel $profileFeed, string $key): bool
    {
        $position = $this->followByKey[strtolower($key)] ?? null;
        if (null === $position) {
            return false;
        }
        $this->subscriptionOfFollow[$position] ??= $profileFeed->subscriptionId;

        return true;
    }

    /**
     * @param list<FollowedAccountModel> $follows
     *
     * @return list<FollowedAccountModel>
     */
    private static function firstOfEachIdentity(array $follows): array
    {
        $byIdentity = [];
        foreach ($follows as $follow) {
            $byIdentity[strtolower($follow->identity)] ??= $follow;
        }

        return array_values($byIdentity);
    }

    /** @return array<string, int> */
    private function indexFollows(): array
    {
        $index = [];
        foreach ($this->follows as $position => $follow) {
            foreach ($this->keysOf($follow) as $key) {
                $index[strtolower($key)] ??= $position;
            }
        }

        return $index;
    }

    /** @return array<int, string> */
    private function keysOf(FollowedAccountModel $follow): array
    {
        return array_filter([
            $follow->identity,
            ltrim($follow->handle, '@'),
            ProfileFeedUrl::identityOf($this->network, $follow->feedUrl),
        ]);
    }
}
