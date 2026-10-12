<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;

final readonly class FollowMatcher
{
    private const int MAX_HANDLE_RESOLUTIONS = 25;

    public function __construct(private BlueskyIdentityResolver $identities)
    {
    }

    /**
     * @param list<ProfileFeedSubscriptionModel> $profileFeeds
     *
     * @return list<ImportCandidateModel>
     */
    public function match(FollowMatching $matching, array $profileFeeds): array
    {
        foreach ($profileFeeds as $profileFeed) {
            $matching->offer($profileFeed);
        }
        $this->resolveLeftoverHandles($matching);

        return $matching->candidates();
    }

    private function resolveLeftoverHandles(FollowMatching $matching): void
    {
        foreach (\array_slice($matching->unmatchedHandles(), 0, self::MAX_HANDLE_RESOLUTIONS) as $profileFeed) {
            try {
                $matching->claimAs($profileFeed, $this->identities->didOf($profileFeed->identity));
            } catch (SocialAccountUnresolvableException) {
                continue;
            } catch (SocialNetworkUnreachableException) {
                return;
            }
        }
    }
}
