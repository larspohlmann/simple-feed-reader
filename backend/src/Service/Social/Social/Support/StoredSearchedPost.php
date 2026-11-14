<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Foundation\Text\Support\PlainText;

/**
 * A stored search result as the reader judges it, its author named as the live search names them: the author page
 * keeps the handle, and a Mastodon account local to the searching instance also goes by its bare user name.
 */
final class StoredSearchedPost
{
    public static function of(SocialAccount $account, StoredPostModel $post): SearchedPostModel
    {
        return new SearchedPostModel(
            self::authorNamesOf($account, $post),
            [PlainText::fromHtmlBlocks($post->contentHtml) ?? ''],
        );
    }

    /** @return list<string> */
    private static function authorNamesOf(SocialAccount $account, StoredPostModel $post): array
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::blueskyNamesOf($post),
            SocialNetwork::Mastodon => self::mastodonNamesOf($account, $post->authorUrl),
        };
    }

    /** @return list<string> the handle the author page shows and the DID the post's at:// address carries */
    private static function blueskyNamesOf(StoredPostModel $post): array
    {
        $handle = null === $post->authorUrl ? null : ProfileFeedUrl::blueskyProfileActorOf($post->authorUrl);

        return array_values(array_filter(
            [$handle, AtPostUri::authorityOf($post->guid)],
            static fn (?string $name): bool => null !== $name,
        ));
    }

    /** @return list<string> */
    private static function mastodonNamesOf(SocialAccount $account, ?string $authorUrl): array
    {
        $identity = MastodonAuthor::identityOfProfileUrl($authorUrl);
        if (null === $identity) {
            return [];
        }
        [$user, $host] = explode('@', $identity, 2);
        $instanceHost = mb_strtolower($account->getServiceHost() ?? '');

        return $host === $instanceHost ? [$identity, $user] : [$identity];
    }

    private function __construct()
    {
    }
}
