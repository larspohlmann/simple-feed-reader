<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Mastodon\MastodonPrivateFeedRule;
use App\Service\Social\Mastodon\Support\MastodonFeedIds;
use App\Service\Social\Mastodon\Support\MastodonSearchPage;
use App\Service\Social\Mastodon\Support\MastodonSearchPath;
use App\Service\Social\Mastodon\Support\MastodonTimelinePage;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\TimelineSource\TimelineSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 40;

    public function __construct(
        private MastodonAccountApi $mastodon,
        private MastodonPrivateFeedRule $privateFeedRule,
    ) {
    }

    public function requests(SocialFeed $socialFeed): array
    {
        $account = $socialFeed->getSocialAccount();

        return array_map(
            fn (string $path): ApiRequestModel => $this->mastodon->authorizedGet($account, $path),
            $this->pathsOf($socialFeed),
        );
    }

    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel
    {
        return MastodonApiClient::accepted(MastodonApiClient::answerOf($outcome));
    }

    public function pageOf(SocialFeed $socialFeed, array $accepted): TimelinePageModel
    {
        if (SocialFeedKind::Search === $socialFeed->getKind()) {
            return MastodonSearchPage::of($accepted, $socialFeed->getRemoteId());
        }

        $answer = $accepted[0] ?? throw new \LogicException('A Mastodon timeline is read with one request.');

        return self::pageFrom($answer);
    }

    public function dropSession(SocialAccount $account): void
    {
    }

    /** @return non-empty-list<string> */
    private function pathsOf(SocialFeed $socialFeed): array
    {
        return SocialFeedKind::Search === $socialFeed->getKind()
            ? $this->searchPathsOf($socialFeed)
            : [self::pathOf($socialFeed)];
    }

    /** @return non-empty-list<string> */
    private function searchPathsOf(SocialFeed $socialFeed): array
    {
        $queries = $this->privateFeedRule->buildSearchQueries($socialFeed->getRemoteId());

        return array_map(
            static fn (string $query): string => MastodonSearchPath::buildPath($query, $socialFeed->getNewestSeenId()),
            $queries,
        );
    }

    private static function pageFrom(ApiResponseModel $accepted): TimelinePageModel
    {
        $statuses = JsonNodeModel::listOf(json_decode($accepted->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }

    private static function listId(SocialFeed $socialFeed): string
    {
        $listId = $socialFeed->getRemoteId();

        return MastodonFeedIds::isListId($listId)
            ? $listId
            : throw new SocialNetworkUnreachableException('The Mastodon list id is not a number.');
    }

    private static function tagName(SocialFeed $socialFeed): string
    {
        $tagName = $socialFeed->getRemoteId();

        return MastodonFeedIds::isTagName($tagName)
            ? $tagName
            : throw new SocialNetworkUnreachableException('The Mastodon tag name is not a hashtag.');
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => self::timelinePath($socialFeed, 'home'),
            SocialFeedKind::UserList => self::timelinePath($socialFeed, 'list/' . self::listId($socialFeed)),
            SocialFeedKind::Tag => self::timelinePath($socialFeed, 'tag/' . rawurlencode(self::tagName($socialFeed))),
            SocialFeedKind::Trending => '/api/v1/trends/statuses?limit=' . self::PAGE_SIZE,
            SocialFeedKind::Search => throw new \LogicException('A search is asked once per alternative.'),
            SocialFeedKind::Author,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Local,
            SocialFeedKind::All => throw new SocialNetworkUnreachableException('Mastodon has no such timeline.'),
        };
    }

    private static function timelinePath(SocialFeed $socialFeed, string $timeline): string
    {
        $newestSeen = $socialFeed->getNewestSeenId();

        return '/api/v1/timelines/' . $timeline . '?limit=' . self::PAGE_SIZE
            . (null === $newestSeen ? '' : '&min_id=' . rawurlencode($newestSeen));
    }
}
