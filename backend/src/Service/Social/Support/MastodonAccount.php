<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\FollowedAccountModel;

final class MastodonAccount
{
    private const string REMOTE_ADDRESS = '/^([^@]+)@([^@]+)$/';
    private const string PROFILE_PATH = '#^/@[^/@]+$#';

    /** @return FollowedAccountModel|null null when the answer names no account */
    public static function follow(JsonNodeModel $account, string $instanceHost): ?FollowedAccountModel
    {
        $accountAddress = $account->string('acct');
        if (null === $accountAddress) {
            return null;
        }
        [$user, $domain] = self::userAndDomain($accountAddress, $instanceHost);
        $identity = $user . '@' . $domain;

        return new FollowedAccountModel(
            $identity,
            '@' . $identity,
            $account->string('display_name'),
            self::profileFeed($account->string('url')) ?? 'https://' . $domain . '/@' . $user . '.rss',
        );
    }

    /** @return array{string, string} a local address is "user", a remote one "user@domain" */
    private static function userAndDomain(string $accountAddress, string $instanceHost): array
    {
        return 1 === preg_match(self::REMOTE_ADDRESS, $accountAddress, $match)
            ? [$match[1], strtolower($match[2])]
            : [$accountAddress, strtolower($instanceHost)];
    }

    private static function profileFeed(?string $profileUrl): ?string
    {
        $parts = null === $profileUrl ? false : parse_url($profileUrl);
        if (false === $parts || !self::isPlainHttps($parts)) {
            return null;
        }

        return 1 === preg_match(self::PROFILE_PATH, $parts['path'] ?? '') ? $profileUrl . '.rss' : null;
    }

    /** @param array<string, int|string> $parts */
    private static function isPlainHttps(array $parts): bool
    {
        return 'https' === ($parts['scheme'] ?? null)
            && isset($parts['host'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    private function __construct()
    {
    }
}
