<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Service\Social\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\ImportRule\ImportRuleInterface;
use App\Service\Social\Social\Model\ImportCandidateModel;
use App\Service\Social\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Social\Pass\FollowMatching;

final readonly class FollowMatcher
{
    private const int MAX_IDENTITY_RESOLUTIONS = 25;

    /**
     * @param list<ProfileFeedSubscriptionModel> $profileFeeds
     *
     * @return list<ImportCandidateModel>
     */
    public function match(FollowMatching $matching, ImportRuleInterface $importRule, array $profileFeeds): array
    {
        foreach ($profileFeeds as $profileFeed) {
            $matching->offer($profileFeed);
        }
        self::resolveLeftoverIdentities($matching, $importRule);

        return $matching->candidates();
    }

    private static function resolveLeftoverIdentities(FollowMatching $matching, ImportRuleInterface $importRule): void
    {
        foreach (\array_slice($matching->unresolvedProfileFeeds(), 0, self::MAX_IDENTITY_RESOLUTIONS) as $profileFeed) {
            try {
                $matching->claimAs($profileFeed, $importRule->fetchResolvedIdentity($profileFeed->identity));
            } catch (SocialAccountUnresolvableException) {
                continue;
            } catch (SocialNetworkUnreachableException) {
                return;
            }
        }
    }
}
