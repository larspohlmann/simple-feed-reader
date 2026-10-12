<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Support\ProfileFeedUrl;

final readonly class BlueskyCandidateSource implements CandidateSourceInterface
{
    private const string GET_FEED_GENERATORS = AppViewClient::BASE_URL . 'app.bsky.feed.getFeedGenerators';
    private const string GET_PREFERENCES = '/xrpc/app.bsky.actor.getPreferences';
    private const string SAVED_FEEDS = 'app.bsky.actor.defs#savedFeedsPrefV2';
    private const string CURATED_LIST = 'app.bsky.graph.defs#curatelist';
    private const int PAGE_SIZE = 100;
    private const int GENERATORS_PER_CALL = 25;

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySessionOpener $sessions,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $customFeeds = $this->customFeeds($this->sessions->openForAccount($account));
        $follows = $this->collect(self::actorPage('app.bsky.graph.getFollows', $account->getIdentity()), 'follows');
        $lists = $this->collect(self::actorPage('app.bsky.graph.getLists', $account->getIdentity()), 'lists');

        return new NetworkCandidatesModel(
            self::follows($follows->items),
            self::curatedLists($lists->items),
            $customFeeds,
            $follows->truncated || $lists->truncated,
        );
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
            if (self::CURATED_LIST === $node->string('purpose') && null !== $uri && null !== $name) {
                $lists[] = new SocialCollectionModel($uri, $name);
            }
        }

        return $lists;
    }

    /** @return list<SocialCollectionModel> */
    private function customFeeds(BlueskySessionModel $session): array
    {
        $uris = $this->savedFeedUris($session);
        $names = $this->generatorNames($uris);

        return array_map(
            static fn (string $uri): SocialCollectionModel => new SocialCollectionModel($uri, $names[$uri] ?? $uri),
            $uris,
        );
    }

    /** @return list<string> */
    private function savedFeedUris(BlueskySessionModel $session): array
    {
        $preferences = $this->json(ApiRequestModel::get(
            $session->pdsUrl . self::GET_PREFERENCES,
            ['Authorization' => 'Bearer ' . $session->accessJwt],
        ))->nodes('preferences');

        $uris = [];
        foreach ($preferences as $preference) {
            if (self::SAVED_FEEDS === $preference->type()) {
                $uris = [...$uris, ...self::feedUris($preference->nodes('items'))];
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param list<JsonNodeModel> $items
     *
     * @return list<string>
     */
    private static function feedUris(array $items): array
    {
        $uris = [];
        foreach ($items as $item) {
            $uri = $item->string('value');
            if ('feed' === $item->string('type') && null !== $uri) {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /**
     * @param list<string> $uris
     *
     * @return array<string, string>
     */
    private function generatorNames(array $uris): array
    {
        $names = [];
        foreach (array_chunk($uris, self::GENERATORS_PER_CALL) as $batch) {
            $query = implode('&', array_map(static fn (string $uri): string => 'feeds=' . rawurlencode($uri), $batch));
            $answer = $this->json(ApiRequestModel::get(self::GET_FEED_GENERATORS . '?' . $query));
            $names = [...$names, ...self::namesIn($answer)];
        }

        return $names;
    }

    /** @return array<string, string> */
    private static function namesIn(JsonNodeModel $answer): array
    {
        $names = [];
        foreach (self::collectionIn($answer, 'feeds') as $generator) {
            $uri = $generator->string('uri');
            $name = $generator->string('displayName');
            if (null !== $uri && null !== $name) {
                $names[$uri] = $name;
            }
        }

        return $names;
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
