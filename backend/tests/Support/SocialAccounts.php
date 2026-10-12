<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;

/** In-memory social accounts for unit tests, their secrets sealed with cipher() for their owner. */
final class SocialAccounts
{
    use AssignsEntityIds;

    public const int OWNER_ID = 7;
    public const string APP_PASSWORD = 'abcd-efgh-ijkl-mnop';
    public const string ACCESS_TOKEN = 'token-1234567890';

    public static function cipher(): SocialCredentialCipher
    {
        return new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('k', 32)));
    }

    public static function owner(): User
    {
        $owner = new User('alice@example.test', new \DateTimeImmutable('2026-07-01 10:00:00'));

        return self::withId($owner, self::OWNER_ID);
    }

    public static function bluesky(): SocialAccount
    {
        return self::blueskyOf(self::owner());
    }

    public static function blueskyOf(User $owner): SocialAccount
    {
        return new SocialAccount(
            $owner,
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://pds.example.test',
            self::cipher()->seal($owner->requireId(), self::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    public static function mastodon(): SocialAccount
    {
        return self::mastodonOf(self::owner());
    }

    public static function mastodonOf(User $owner): SocialAccount
    {
        return self::mastodonAt($owner, 'https://mastodon.example');
    }

    public static function mastodonOn(string $instanceUrl): SocialAccount
    {
        return self::mastodonAt(self::owner(), $instanceUrl);
    }

    private static function mastodonAt(User $owner, string $instanceUrl): SocialAccount
    {
        return new SocialAccount(
            $owner,
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            $instanceUrl,
            self::cipher()->seal($owner->requireId(), self::ACCESS_TOKEN),
            '7890',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }
}
