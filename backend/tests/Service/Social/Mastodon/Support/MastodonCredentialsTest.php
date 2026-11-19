<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Mastodon\Support\MastodonCredentials;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class MastodonCredentialsTest extends TestCase
{
    public function testASecretTypedWithTrailingWhitespaceIsStoredTrimmedWithTheRightHint(): void
    {
        $credentials = MastodonCredentials::buildCredentials('mastodon.social', "token-1234\n");

        self::assertSame(SocialNetwork::Mastodon, $credentials->network);
        self::assertSame('token-1234', $credentials->secret);
        self::assertSame('1234', $credentials->hint);
    }

    public function testAnInstanceUrlTypedWithSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame(
            'https://mastodon.social',
            MastodonCredentials::buildCredentials("  https://mastodon.social \n", 'token-1234')->address,
        );
    }

    public function testAnUnsafeInstanceUrlIsRefused(): void
    {
        $this->expectException(InvalidInstanceUrlException::class);

        MastodonCredentials::buildCredentials('http://mastodon.social', 'token-1234');
    }

    public function testReconnectingAMastodonAccountTakesATrimmedTokenOfAnyShape(): void
    {
        $account = SocialAccounts::mastodonAt(SocialAccounts::owner(), '42', 'https://mastodon.social');

        self::assertSame(
            'hunter2hunter2',
            MastodonCredentials::buildReconnectCredentials(
                $account,
                new CredentialReplacementModel(" hunter2hunter2\n", null),
            )->secret,
        );
    }

    public function testReconnectingAMastodonAccountAddressesItByItsStoredServiceUrl(): void
    {
        $account = SocialAccounts::mastodonAt(
            SocialAccounts::owner(),
            'https://mastodon.social/users/lars',
            'https://mastodon.social',
        );

        $credentials = MastodonCredentials::buildReconnectCredentials(
            $account,
            new CredentialReplacementModel('token-1234', null),
        );

        self::assertSame(SocialNetwork::Mastodon, $credentials->network);
        self::assertSame('https://mastodon.social', $credentials->address);
        self::assertSame('1234', $credentials->hint);
    }
}
