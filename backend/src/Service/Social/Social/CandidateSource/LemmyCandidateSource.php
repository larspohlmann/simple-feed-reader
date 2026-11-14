<?php

declare(strict_types=1);

namespace App\Service\Social\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\Model\CollectedItemsModel;
use App\Service\Social\Social\Model\FollowedAccountModel;
use App\Service\Social\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Social\Support\LemmyCommunity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyCandidateSource implements CandidateSourceInterface
{
    private const string COMMUNITIES = '/api/v3/community/list?type_=Subscribed&sort=Old&limit=50&page=';
    private const int PAGE_SIZE = 50;

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $communities = $this->communities($account);

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

    private function communities(SocialAccount $account): CollectedItemsModel
    {
        $items = [];
        $page = 1;
        do {
            $fresh = $this->pageOf($account, $page++);
            array_push($items, ...$fresh);
        } while (self::PAGE_SIZE === \count($fresh) && \count($items) <= CollectedItemsModel::MAX_ITEMS);

        return CollectedItemsModel::capped($items);
    }

    /** @return list<JsonNodeModel> */
    private function pageOf(SocialAccount $account, int $page): array
    {
        $answer = $this->lemmy->get($account, self::COMMUNITIES . $page);

        return JsonNodeModel::of(json_decode($answer->body, true))->listAt('communities')
            ?? throw new SocialNetworkUnreachableException('The Lemmy instance answered without communities.');
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
