<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySavedCollections;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Support\CuratedBlueskyList;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class BlueskyCandidateSource implements CandidateSourceInterface
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySavedCollections $savedCollections,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $collections = $this->collections($account);
        $follows = $this->collect(self::actorPage('app.bsky.graph.getFollows', $account->getIdentity()), 'follows');

        return new NetworkCandidatesModel(
            self::follows($follows->items),
            $collections->lists,
            $collections->customFeeds,
            $follows->truncated || $collections->truncated,
        );
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        $saved = $this->savedCollections->savedItemsOf($account);
        $owned = $this->collect(self::actorPage('app.bsky.graph.getLists', $account->getIdentity()), 'lists');
        $ownedLists = self::curatedLists($owned->items);
        $others = $this->savedCollections->resolve($saved, self::urisOf($ownedLists));

        return new NetworkCollectionsModel(
            [...$ownedLists, ...$others->lists],
            $others->customFeeds,
            $others->goneIds,
            truncated: $owned->truncated,
            savedListsCapped: $others->savedListsCapped,
            savedFeedsKnown: $others->savedFeedsKnown,
        );
    }

    /**
     * @param list<SocialCollectionModel> $lists
     *
     * @return array<string, true>
     */
    private static function urisOf(array $lists): array
    {
        return array_fill_keys(array_map(static fn (SocialCollectionModel $list): string => $list->id, $lists), true);
    }

    private static function actorPage(string $method, string $did): string
    {
        return AppViewClient::BASE_URL . $method . '?actor=' . rawurlencode($did) . '&limit=' . self::PAGE_SIZE;
    }

    private function collect(string $firstPage, string $field): CollectedItemsModel
    {
        $items = [];
        $cursor = null;
        do {
            $page = $this->json(ApiRequestModel::get(
                null === $cursor ? $firstPage : $firstPage . '&cursor=' . rawurlencode($cursor),
            ));
            $fresh = self::collectionIn($page, $field);
            array_push($items, ...$fresh);
            $cursor = $page->string('cursor');
        } while (null !== $cursor && [] !== $fresh && \count($items) <= CollectedItemsModel::MAX_ITEMS);

        return CollectedItemsModel::capped($items);
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<FollowedAccountModel>
     */
    private static function follows(array $nodes): array
    {
        $follows = [];
        foreach ($nodes as $node) {
            $did = $node->string('did');
            $handle = $node->string('handle');
            if (null !== $did && null !== $handle) {
                $follows[] = new FollowedAccountModel(
                    $did,
                    '@' . $handle,
                    $node->string('displayName'),
                    ProfileFeedUrl::bluesky($did),
                    AbsoluteHttpUrl::orNull($node->string('avatar')),
                );
            }
        }

        return $follows;
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<SocialCollectionModel>
     */
    private static function curatedLists(array $nodes): array
    {
        $lists = [];
        foreach ($nodes as $node) {
            $uri = $node->string('uri');
            $name = $node->string('name');
            if (CuratedBlueskyList::isPurpose($node->string('purpose')) && null !== $uri && null !== $name) {
                $lists[] = new SocialCollectionModel($uri, $name);
            }
        }

        return $lists;
    }

    /** @return list<JsonNodeModel> */
    private static function collectionIn(JsonNodeModel $answer, string $field): array
    {
        return $answer->listAt($field)
            ?? throw new SocialNetworkUnreachableException(sprintf('Bluesky answered without %s.', $field));
    }

    private function json(ApiRequestModel $request): JsonNodeModel
    {
        return JsonNodeModel::of($this->bluesky->send($request)->json());
    }
}
