<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;

/** Who wrote an entry's post, as an account identity: the DID of its AT URI, or user@host of its Mastodon author. */
final class PostAuthor
{
    private const string MASTODON_PROFILE = '~^https://([a-z0-9.-]+)/(?:@|users/)([^/?#@]+)/?$~iD';

    /** @param list<SocialAccount> $accounts */
    public static function accountAmong(Entry $entry, array $accounts): ?SocialAccount
    {
        $author = self::identityOf($entry);
        foreach ($accounts as $account) {
            if (SocialIdentity::isAccount($author, $account)) {
                return $account;
            }
        }

        return null;
    }

    private static function identityOf(Entry $entry): ?string
    {
        $post = SocialPostAddress::of($entry);
        if (null === $post) {
            return null;
        }

        return match ($post->network) {
            SocialNetwork::Bluesky => AtPostUri::authorityOf($post->address),
            SocialNetwork::Mastodon => self::mastodonIdentityOf(
                $entry->getAuthorUrl() ?? MastodonStatusAddress::ofOrNull($post->address)?->authorPrefix,
            ),
        };
    }

    private static function mastodonIdentityOf(?string $profileUrl): ?string
    {
        if (null === $profileUrl || 1 !== preg_match(self::MASTODON_PROFILE, $profileUrl, $match)) {
            return null;
        }

        return mb_strtolower($match[2] . '@' . $match[1]);
    }

    private function __construct()
    {
    }
}
