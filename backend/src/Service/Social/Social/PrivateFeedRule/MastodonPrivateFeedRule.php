<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PrivateFeedRule;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Social\Social\Pass\SearchQueryAlternatives;
use App\Service\Social\Social\Support\MastodonAuthor;
use App\Service\Social\Social\Support\MastodonFeedIds;
use App\Service\Social\Social\Support\SearchQueryTokens;
use App\Service\Social\Social\Support\SocialSearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPrivateFeedRule implements PrivateFeedRuleInterface
{
    public function allows(PrivateFeedModel $privateFeed): bool
    {
        $remoteId = $privateFeed->remoteId;

        return match ($privateFeed->kind) {
            SocialFeedKind::Home, SocialFeedKind::Trending => true,
            SocialFeedKind::UserList => MastodonFeedIds::isListId($remoteId),
            SocialFeedKind::Tag => MastodonFeedIds::isTagName($remoteId),
            SocialFeedKind::Search => SocialSearchQuery::isValid($remoteId)
                && SocialSearchQuery::isSearchable($this->buildSearchQueries(...), $remoteId),
            SocialFeedKind::CustomFeed, SocialFeedKind::Author, SocialFeedKind::Local, SocialFeedKind::All => false,
        };
    }

    public function buildSiteUrl(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');

        return match ($privateFeed->kind) {
            SocialFeedKind::UserList => $instance . '/lists/' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Tag => $instance . '/tags/' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Trending => $instance . '/explore',
            SocialFeedKind::Search => $instance . '/search?q=' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Home,
            SocialFeedKind::Author,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Local,
            SocialFeedKind::All => $instance,
        };
    }

    public function ownsList(SocialAccount $account, PrivateFeedModel $list): bool
    {
        return true;
    }

    public function sourceFormat(): string
    {
        return SourceFormat::MASTODON_API;
    }

    public function buildSearchQueries(string $query): array
    {
        return new SearchQueryAlternatives(SearchQueryTokens::of($query))->queries();
    }

    public function buildSearchedPost(SocialAccount $account, StoredPostModel $post): SearchedPostModel
    {
        return new SearchedPostModel(
            self::authorNamesOf($account, $post->authorUrl),
            [PlainText::fromHtmlBlocks($post->contentHtml) ?? ''],
        );
    }

    /** @return list<string> */
    private static function authorNamesOf(SocialAccount $account, ?string $authorUrl): array
    {
        $identity = MastodonAuthor::identityOfProfileUrl($authorUrl);
        if (null === $identity) {
            return [];
        }
        [$user, $host] = explode('@', $identity, 2);
        $instanceHost = mb_strtolower($account->getServiceHost() ?? '');

        return $host === $instanceHost ? [$identity, $user] : [$identity];
    }
}
