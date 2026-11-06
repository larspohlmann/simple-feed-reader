<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;

/** A Mastodon post on the reader's own instance, which resolves the post's address and redirects to its local copy. */
final class OwnInstancePostUrl
{
    private const string AUTHORIZE_INTERACTION = '%s/authorize_interaction?uri=%s';

    /**
     * @param non-empty-list<SocialAccount> $accounts
     *
     * @return string|null null when the post already lives on that instance
     */
    public static function among(array $accounts, string $postAddress): ?string
    {
        $instance = rtrim(self::actingAmong($accounts)->getServiceUrl(), '/');
        if (self::hostOf($instance) === self::hostOf($postAddress)) {
            return null;
        }

        return sprintf(self::AUTHORIZE_INTERACTION, $instance, rawurlencode($postAddress));
    }

    /** @param non-empty-list<SocialAccount> $accounts */
    private static function actingAmong(array $accounts): SocialAccount
    {
        return array_find($accounts, static fn (SocialAccount $account): bool => $account->isDefault()) ?? $accounts[0];
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    private function __construct()
    {
    }
}
