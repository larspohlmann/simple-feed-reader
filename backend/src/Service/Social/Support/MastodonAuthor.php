<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

final class MastodonAuthor
{
    private const string PROFILE_URL = '~^https://([a-z0-9.-]+)/(?:@|users/)([^/?#@]+)/?$~iD';

    public static function name(JsonNodeModel $account): ?string
    {
        return $account->string('display_name') ?? $account->string('acct');
    }

    /** A local account's acct is the bare user name: the profile's host completes it. */
    public static function handle(JsonNodeModel $account): ?string
    {
        $acct = $account->string('acct');
        $host = parse_url((string) $account->string('url'), \PHP_URL_HOST);
        if ($acct === null || str_contains($acct, '@') || !\is_string($host)) {
            return $acct;
        }

        return $acct . '@' . $host;
    }

    /** The account as a social account identity, which is its handle in lower case. */
    public static function identity(JsonNodeModel $account): ?string
    {
        $handle = self::handle($account);

        return null === $handle ? null : mb_strtolower($handle);
    }

    /** The identity of the account a profile URL names: its `/@user` or `/users/user` page on its own server. */
    public static function identityOfProfileUrl(?string $profileUrl): ?string
    {
        if (null === $profileUrl || 1 !== preg_match(self::PROFILE_URL, $profileUrl, $match)) {
            return null;
        }

        return mb_strtolower($match[2] . '@' . $match[1]);
    }

    public static function avatar(JsonNodeModel $account): ?string
    {
        return AbsoluteHttpUrl::orNull($account->string('avatar_static'))
            ?? AbsoluteHttpUrl::orNull($account->string('avatar'));
    }

    public static function profile(JsonNodeModel $account): ?string
    {
        return AbsoluteHttpUrl::orNull($account->string('url'));
    }

    private function __construct()
    {
    }
}
