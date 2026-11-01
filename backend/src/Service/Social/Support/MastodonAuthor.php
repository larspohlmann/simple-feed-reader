<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

final class MastodonAuthor
{
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
