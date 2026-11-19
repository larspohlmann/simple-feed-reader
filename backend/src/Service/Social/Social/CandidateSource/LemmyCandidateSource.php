<?php

declare(strict_types=1);

namespace App\Service\Social\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Social\LemmyCommunities;
use App\Service\Social\Social\Model\FollowedAccountModel;
use App\Service\Social\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Social\Support\LemmyCommunity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyCandidateSource implements CandidateSourceInterface
{
    public function __construct(private LemmyCommunities $communities)
    {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $communities = $this->communities->subscribedViews($account);

        return new NetworkCandidatesModel(
            self::followedCommunities($communities->items),
            [],
            [],
            $communities->truncated,
        );
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        return new NetworkCollectionsModel([], []);
    }

    /**
     * @param list<JsonNodeModel> $views
     *
     * @return list<FollowedAccountModel>
     */
    private static function followedCommunities(array $views): array
    {
        return array_values(array_filter(array_map(LemmyCommunity::follow(...), $views)));
    }
}
