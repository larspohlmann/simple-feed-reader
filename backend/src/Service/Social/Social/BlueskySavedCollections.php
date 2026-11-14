<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\ResolvedCollectionsModel;
use App\Service\Social\Model\SavedItemsModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Support\CuratedBlueskyList;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskySavedCollections
{
    public const int MAX_SAVED_LISTS = 25;

    private const string GET_FEED_GENERATORS = AppViewClient::BASE_URL . 'app.bsky.feed.getFeedGenerators';
    private const string GET_LIST = AppViewClient::BASE_URL . 'app.bsky.graph.getList';
    private const string GET_PREFERENCES = '/xrpc/app.bsky.actor.getPreferences';
    private const string SAVED_FEEDS = 'app.bsky.actor.defs#savedFeedsPrefV2';
    private const string MISSING_RECORD = 'InvalidRequest';
    private const string DID_URI_PREFIX = 'at://did:';
    private const int GENERATORS_PER_CALL = 25;

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySessions $sessions,
    ) {
    }

    public function savedItemsOf(SocialAccount $account): SavedItemsModel
    {
        $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_PREFERENCES));
        $preferences = JsonNodeModel::of($answer->json())->listAt('preferences')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without preferences.');

        $saved = ['feed' => [], 'list' => []];
        $hasSavedFeedsPref = false;
        foreach ($preferences as $preference) {
            if (self::SAVED_FEEDS !== $preference->type()) {
                continue;
            }
            $hasSavedFeedsPref = true;
            $items = $preference->listAt('items')
                ?? throw new SocialNetworkUnreachableException('Bluesky answered saved feeds without items.');
            foreach ($items as $item) {
                $type = $item->string('type');
                $value = $item->string('value');
                if (null !== $value && isset($saved[$type ?? ''])) {
                    $saved[$type][] = $value;
                }
            }
        }

        return new SavedItemsModel(
            array_values(array_unique($saved['feed'])),
            array_values(array_unique($saved['list'])),
            $hasSavedFeedsPref,
        );
    }

    /** @param array<string, true> $ownedListUris */
    public function resolve(SavedItemsModel $saved, array $ownedListUris): NetworkCollectionsModel
    {
        $othersLists = array_values(array_filter(
            $saved->listUris,
            static fn (string $uri): bool => !isset($ownedListUris[$uri]),
        ));
        $feeds = $this->generators($saved->feedUris);
        $lists = $this->savedLists(\array_slice($othersLists, 0, self::MAX_SAVED_LISTS));

        return new NetworkCollectionsModel(
            $lists->found,
            $feeds->found,
            [...$feeds->gone, ...$lists->gone],
            savedListsCapped: \count($othersLists) > self::MAX_SAVED_LISTS,
            savedFeedsKnown: $saved->hasSavedFeedsPref,
        );
    }

    /** @param list<string> $uris */
    private function generators(array $uris): ResolvedCollectionsModel
    {
        $names = [];
        foreach (array_chunk($uris, self::GENERATORS_PER_CALL) as $batch) {
            $query = implode('&', array_map(static fn (string $uri): string => 'feeds=' . rawurlencode($uri), $batch));
            $request = ApiRequestModel::get(self::GET_FEED_GENERATORS . '?' . $query);
            $answer = JsonNodeModel::of($this->bluesky->send($request)->json());
            $names = [...$names, ...self::namesIn($answer)];
        }
        $found = [];
        $gone = [];
        foreach ($uris as $uri) {
            if (\array_key_exists($uri, $names)) {
                $found[] = new SocialCollectionModel($uri, $names[$uri] ?? $uri);
            } elseif (str_starts_with($uri, self::DID_URI_PREFIX)) {
                $gone[] = $uri;
            }
        }

        return new ResolvedCollectionsModel($found, $gone);
    }

    /** @param list<string> $uris */
    private function savedLists(array $uris): ResolvedCollectionsModel
    {
        $found = [];
        $gone = [];
        foreach ($uris as $uri) {
            $answer = $this->bluesky->answer(
                ApiRequestModel::get(self::GET_LIST . '?list=' . rawurlencode($uri) . '&limit=1'),
            );
            if (self::isMissing($answer)) {
                $gone[] = $uri;
                continue;
            }
            $list = JsonNodeModel::of(BlueskyApiClient::accepted($answer)->json())->node('list');
            $collection = self::curatedListIn($list);
            if (null !== $collection) {
                $found[] = $collection;
            }
        }

        return new ResolvedCollectionsModel($found, $gone);
    }

    /** @throws SocialNetworkUnreachableException when the answer lacks the list's uri, purpose or name */
    private static function curatedListIn(JsonNodeModel $list): ?SocialCollectionModel
    {
        $uri = $list->string('uri');
        $purpose = $list->string('purpose');
        $name = $list->string('name');
        if (null === $uri || null === $purpose || null === $name) {
            throw new SocialNetworkUnreachableException('Bluesky answered without the saved list.');
        }

        return CuratedBlueskyList::isPurpose($purpose) ? new SocialCollectionModel($uri, $name) : null;
    }

    /** @return array<string, string|null> display name by uri; null when the generator has none */
    private static function namesIn(JsonNodeModel $answer): array
    {
        $generators = $answer->listAt('feeds')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without feeds.');
        $names = [];
        foreach ($generators as $generator) {
            $uri = $generator->string('uri');
            if (null === $uri) {
                continue;
            }
            $name = $generator->string('displayName');
            foreach ([$uri, self::handleFormOf($uri, $generator->node('creator')->string('handle'))] as $key) {
                $names[$key] = $name ?? ($names[$key] ?? null);
            }
        }

        return $names;
    }

    private static function handleFormOf(string $uri, ?string $creatorHandle): string
    {
        return null === $creatorHandle
            ? $uri
            : 'at://' . $creatorHandle . '/' . preg_replace('#^at://[^/]+/#', '', $uri);
    }

    private static function isMissing(ApiResponseModel $answer): bool
    {
        return Response::HTTP_BAD_REQUEST === $answer->status
            && self::MISSING_RECORD === JsonNodeModel::of($answer->json())->string('error');
    }
}
