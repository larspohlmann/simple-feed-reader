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
    /** @param list<SocialAccount> $accounts */
    public static function accountAmong(Entry $entry, array $accounts): ?SocialAccount
    {
        return SocialIdentity::accountAmong(self::identityOf($entry), $accounts);
    }

    private static function identityOf(Entry $entry): ?string
    {
        $post = SocialPostAddress::of($entry);
        if (null === $post) {
            return null;
        }

        return match ($post->network) {
            SocialNetwork::Bluesky => AtPostUri::authorityOf($post->address),
            SocialNetwork::Mastodon => MastodonAuthor::identityOfProfileUrl(
                $entry->getAuthorUrl() ?? MastodonStatusAddress::ofOrNull($post->address)?->authorPrefix,
            ),
        };
    }

    private function __construct()
    {
    }
}
