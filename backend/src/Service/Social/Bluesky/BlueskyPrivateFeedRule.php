<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\BlueskyPost\Support\BlueskyWeb;
use App\Service\Social\Bluesky\Support\BlueskyActor;
use App\Service\Social\Bluesky\Support\BlueskyProfileFeed;
use App\Service\Social\Bluesky\Support\BlueskyRecordUri;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Social\Social\PrivateFeedRule\PrivateFeedRuleInterface;
use App\Service\Social\Social\Support\SocialSearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPrivateFeedRule implements PrivateFeedRuleInterface
{
    public function allows(PrivateFeedModel $privateFeed): bool
    {
        $remoteId = $privateFeed->remoteId;

        return match ($privateFeed->kind) {
            SocialFeedKind::Home => true,
            SocialFeedKind::UserList => BlueskyRecordUri::isList($remoteId),
            SocialFeedKind::CustomFeed => BlueskyRecordUri::isFeedGenerator($remoteId),
            SocialFeedKind::Author => BlueskyActor::isDid($remoteId),
            SocialFeedKind::Search => SocialSearchQuery::isValid($remoteId),
            SocialFeedKind::Tag, SocialFeedKind::Trending, SocialFeedKind::Local, SocialFeedKind::All => false,
        };
    }

    public function buildSiteUrl(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        return match (true) {
            SocialFeedKind::Search === $privateFeed->kind => BlueskyWeb::searchUrl($privateFeed->remoteId),
            $privateFeed->kind->followsOnePerson() => BlueskyWeb::profileUrl($privateFeed->remoteId),
            default => BlueskyRecordUri::webUrl($privateFeed->remoteId)
                ?? BlueskyWeb::profileUrl($account->getIdentity()),
        };
    }

    public function ownsList(SocialAccount $account, PrivateFeedModel $list): bool
    {
        return str_starts_with($list->remoteId, 'at://' . $account->getIdentity() . '/');
    }

    public function sourceFormat(): string
    {
        return SourceFormat::BLUESKY_API;
    }

    public function hasFollowersOnlyPosts(): bool
    {
        return false;
    }

    public function buildSearchQueries(string $query): array
    {
        return [$query];
    }

    public function buildSearchedPost(SocialAccount $account, StoredPostModel $post): SearchedPostModel
    {
        return new SearchedPostModel(self::authorNamesOf($post), [PlainText::fromHtmlBlocks($post->contentHtml) ?? '']);
    }

    /** @return list<string> the handle the author page shows and the DID the post's at:// address carries */
    private static function authorNamesOf(StoredPostModel $post): array
    {
        $handle = null === $post->authorUrl ? null : BlueskyProfileFeed::parseProfileActor($post->authorUrl);

        return array_values(array_filter(
            [$handle, AtPostUri::authorityOf($post->guid)],
            static fn (?string $name): bool => null !== $name,
        ));
    }
}
