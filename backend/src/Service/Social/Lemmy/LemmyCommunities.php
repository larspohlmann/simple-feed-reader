<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Lemmy\Model\LemmyListing;
use App\Service\Social\Lemmy\Support\LemmyCommunity;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\CollectedItemsModel;
use App\Service\Social\Social\Model\CommunityModel;

final readonly class LemmyCommunities
{
    private const string COMMUNITIES = '/api/v3/community/list?';

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function subscribedViews(SocialAccount $account): CollectedItemsModel
    {
        $views = [];
        $page = 1;
        do {
            $fresh = $this->pageOf($account, $page++);
            array_push($views, ...$fresh);
        } while (LemmyListing::PAGE_SIZE === \count($fresh) && \count($views) <= CollectedItemsModel::MAX_ITEMS);

        return CollectedItemsModel::capped($views);
    }

    /** @return list<CommunityModel> */
    public function postable(SocialAccount $account): array
    {
        $communities = [];
        foreach ($this->subscribedViews($account)->items as $view) {
            $community = $view->node('community');
            $identity = LemmyCommunity::identityOf($community);
            $id = $community->int('id');
            if (null === $identity || null === $id || self::closedToPosts($community)) {
                continue;
            }
            $communities[] = new CommunityModel($id, '!' . $identity);
        }
        usort(
            $communities,
            static fn (CommunityModel $left, CommunityModel $right): int => $left->handle <=> $right->handle,
        );

        return $communities;
    }

    private static function closedToPosts(JsonNodeModel $community): bool
    {
        return $community->isTrue('removed')
            || $community->isTrue('deleted')
            || $community->isTrue('posting_restricted_to_mods');
    }

    /** @return list<JsonNodeModel> */
    private function pageOf(SocialAccount $account, int $page): array
    {
        $answer = $this->lemmy->get($account, self::COMMUNITIES . http_build_query([
            'type_' => LemmyListing::Subscribed->value,
            'sort' => 'Old',
            'limit' => LemmyListing::PAGE_SIZE,
            'page' => $page,
        ]));

        return JsonNodeModel::fromValue($answer->json())->listAt('communities')
            ?? throw new SocialNetworkUnreachableException('The Lemmy instance answered without communities.');
    }
}
