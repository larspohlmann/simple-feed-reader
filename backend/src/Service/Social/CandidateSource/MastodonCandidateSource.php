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
use Symfony\Component\HttpFoundation\Response;

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
        $follows = $this->follows($session);

        return new NetworkCandidatesModel(
            self::followedAccounts($follows->items, $session->host()),
            $this->lists($session),
            [],
            $follows->truncated,
            tags: self::tagsOf($this->followedTags($session)),
        );
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        $session = $this->sessionOf($account);
        $lists = $this->lists($session);
        $tags = $this->followedTags($session);

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

    private function follows(MastodonSessionModel $session): CollectedItemsModel
    {
        $following = '/api/v1/accounts/' . rawurlencode($this->ownAccountId($session))
            . '/following?limit=' . self::PAGE_SIZE;

        return $this->collectFrom($session, $this->mastodon->send($session->get($session->url($following))));
    }

    private function followedTags(MastodonSessionModel $session): CollectedItemsModel
    {
        $firstPage = $this->mastodon->answer($session->get($session->url(self::FOLLOWED_TAGS)));
        if (Response::HTTP_NOT_FOUND === $firstPage->status) {
            return CollectedItemsModel::capped([]);
        }

        return $this->collectFrom($session, MastodonApiClient::accepted($firstPage));
    }

    private function collectFrom(MastodonSessionModel $session, ApiResponseModel $firstPage): CollectedItemsModel
    {
        $items = [];
        $page = $firstPage;
        do {
            $fresh = self::nodesOf($page);
            array_push($items, ...$fresh);
            $next = LinkHeader::next($page->header('link'));
            if (null !== $next && !LinkHeader::isOn($session->instanceUrl, $next)) {
                return CollectedItemsModel::cutShort($items);
            }
            $page = null === $next || [] === $fresh || \count($items) > CollectedItemsModel::MAX_ITEMS
                ? null
                : $this->mastodon->send($session->get($next));
        } while (null !== $page);

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
    private static function followedAccounts(array $nodes, string $instanceHost): array
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
