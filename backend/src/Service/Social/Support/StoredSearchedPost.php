<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\SearchedPostModel;
use App\Service\Social\Model\StoredPostModel;
use App\Service\Text\Support\PlainText;

/**
 * A stored search result as the reader judges it, its author named as the live search names them: the author page
 * keeps the handle, and a Mastodon account local to the searching instance also goes by its bare user name.
 */
final class StoredSearchedPost
{
    public static function of(SocialAccount $account, StoredPostModel $post): SearchedPostModel
    {
        return new SearchedPostModel(
            self::authorNamesOf($account, $post->authorUrl),
            [PlainText::fromHtmlBlocks($post->contentHtml) ?? ''],
        );
    }

    /** @return list<string> */
    private static function authorNamesOf(SocialAccount $account, ?string $authorUrl): array
    {
        if (null === $authorUrl) {
            return [];
        }

        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::blueskyNamesOf($authorUrl),
            SocialNetwork::Mastodon => self::mastodonNamesOf($account, $authorUrl),
        };
    }

    /** @return list<string> */
    private static function blueskyNamesOf(string $authorUrl): array
    {
        $actor = ProfileFeedUrl::blueskyProfileActorOf($authorUrl);

        return null === $actor ? [] : [$actor];
    }

    /** @return list<string> */
    private static function mastodonNamesOf(SocialAccount $account, string $authorUrl): array
    {
        $identity = MastodonAuthor::identityOfProfileUrl($authorUrl);
        if (null === $identity) {
            return [];
        }
        [$user, $host] = explode('@', $identity, 2);
        $instanceHost = mb_strtolower((string) parse_url($account->getServiceUrl(), \PHP_URL_HOST));

        return $host === $instanceHost ? [$identity, $user] : [$identity];
    }

    private function __construct()
    {
    }
}
