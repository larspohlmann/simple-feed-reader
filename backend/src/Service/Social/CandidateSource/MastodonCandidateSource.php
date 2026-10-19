<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\MastodonSessionModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Service\Social\Support\LinkHeader;
use App\Service\Social\Support\MastodonAccount;
use App\Service\Social\Support\MastodonFollowedTag;

final readonly class MastodonCandidateSource implements CandidateSourceInterface
{
    private const string VERIFY_CREDENTIALS = '/api/v1/accounts/verify_credentials';
    private const string LISTS = '/api/v1/lists';
    private const string FOLLOWED_TAGS = '/api/v1/followed_tags?limit=200';
    private const int PAGE_SIZE = 80;

    public function __construct(
        private MastodonApiClient $mastodon,
        private StoredCredential $storedCredential,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $session = $this->sessionOf($account);
        $follows = $this->collectPages($session, $this->followingUrl($session));

        return new NetworkCandidatesModel(
            self::follows($follows->items, $session->host()),
            $this->lists($session),
            [],
            $follows->truncated,
            tags: self::tagsOf($this->collectPages($session, $session->url(self::FOLLOWED_TAGS))),
        );
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        $session = $this->sessionOf($account);
        $lists = $this->lists($session);
        $tags = $this->collectPages($session, $session->url(self::FOLLOWED_TAGS));

        return new NetworkCollectionsModel($lists, [], truncated: $tags->truncated, tags: self::tagsOf($tags));
    }

    private function sessionOf(SocialAccount $account): MastodonSessionModel
    {
        return new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
    }

    private function ownAccountId(MastodonSessionModel $session): string
    {
        $answer = $this->mastodon->send($session->get($session->url(self::VERIFY_CREDENTIALS)));

        return JsonNodeModel::of($answer->json())->string('id')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance gave no account for this token.');
    }

    private function followingUrl(MastodonSessionModel $session): string
    {
        return $session->url(
            '/api/v1/accounts/' . rawurlencode($this->ownAccountId($session)) . '/following?limit=' . self::PAGE_SIZE,
        );
    }

    private function collectPages(MastodonSessionModel $session, string $firstPage): CollectedItemsModel
    {
        $items = [];
        $url = $firstPage;
        do {
            $answer = $this->mastodon->send($session->get($url));
            $fresh = self::nodesOf($answer);
            array_push($items, ...$fresh);
            $url = LinkHeader::next($answer->header('link'));
            if (null !== $url && !LinkHeader::isOn($session->instanceUrl, $url)) {
                return CollectedItemsModel::cutShort($items);
            }
        } while (null !== $url && [] !== $fresh && \count($items) <= CollectedItemsModel::MAX_ITEMS);

        return CollectedItemsModel::capped($items);
    }

    /** @return list<SocialCollectionModel> */
    private static function tagsOf(CollectedItemsModel $tags): array
    {
        return array_values(array_filter(array_map(MastodonFollowedTag::collectionOf(...), $tags->items)));
    }

    /** @return list<SocialCollectionModel> */
    private function lists(MastodonSessionModel $session): array
    {
        $lists = [];
        foreach (self::nodesOf($this->mastodon->send($session->get($session->url(self::LISTS)))) as $node) {
            $id = $node->string('id');
            $title = $node->string('title');
            if (null !== $id && null !== $title) {
                $lists[] = new SocialCollectionModel($id, $title);
            }
        }

        return $lists;
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<FollowedAccountModel>
     */
    private static function follows(array $nodes, string $instanceHost): array
    {
        $follows = [];
        foreach ($nodes as $node) {
            $follow = MastodonAccount::follow($node, $instanceHost);
            if (null !== $follow) {
                $follows[] = $follow;
            }
        }

        return $follows;
    }

    /** @return list<JsonNodeModel> */
    private static function nodesOf(ApiResponseModel $answer): array
    {
        return JsonNodeModel::listOf(json_decode($answer->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a list.');
    }
}
