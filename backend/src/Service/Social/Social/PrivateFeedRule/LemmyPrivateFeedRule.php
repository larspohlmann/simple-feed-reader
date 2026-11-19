<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PrivateFeedRule;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\LemmyListing;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Social\Social\Pass\SearchQueryAlternatives;
use App\Service\Social\Social\Support\LemmySearchedPost;
use App\Service\Social\Social\Support\LemmySearchTerms;
use App\Service\Social\Social\Support\SearchQueryTokens;
use App\Service\Social\Social\Support\SocialSearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPrivateFeedRule implements PrivateFeedRuleInterface
{
    public function allows(PrivateFeedModel $privateFeed): bool
    {
        $remoteId = $privateFeed->remoteId;

        return match ($privateFeed->kind) {
            SocialFeedKind::Home, SocialFeedKind::Local, SocialFeedKind::All => true,
            SocialFeedKind::Search => SocialSearchQuery::isValid($remoteId) && $this->isSearchable($remoteId),
            SocialFeedKind::UserList,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Author,
            SocialFeedKind::Tag,
            SocialFeedKind::Trending => false,
        };
    }

    public function buildSiteUrl(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');
        $listing = LemmyListing::ofFeedKind($privateFeed->kind);
        if (null !== $listing) {
            return $instance . '/?listingType=' . $listing->value;
        }

        return SocialFeedKind::Search === $privateFeed->kind
            ? $instance . '/search?q=' . rawurlencode($privateFeed->remoteId) . '&type=Posts'
            : $instance;
    }

    public function ownsList(SocialAccount $account, PrivateFeedModel $list): bool
    {
        return false;
    }

    public function sourceFormat(): string
    {
        return SourceFormat::LEMMY_API;
    }

    public function buildSearchQueries(string $query): array
    {
        return LemmySearchTerms::of(new SearchQueryAlternatives(SearchQueryTokens::of($query))->factors());
    }

    public function buildSearchedPost(SocialAccount $account, StoredPostModel $post): SearchedPostModel
    {
        return LemmySearchedPost::of($post->title, $post->contentHtml, $post->authorUrl);
    }

    private function isSearchable(string $query): bool
    {
        try {
            $this->buildSearchQueries($query);
        } catch (SearchQueryTooBroadException) {
            return false;
        }

        return true;
    }
}
