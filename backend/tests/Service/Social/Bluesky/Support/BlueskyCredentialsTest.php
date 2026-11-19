<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\NotAnAppPasswordException;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Bluesky\Support\BlueskyCredentials;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class BlueskyCredentialsTest extends TestCase
{
    public function testABlueskyHandleIsTrimmedLowercasedAndLosesItsAt(): void
    {
        $credentials = BlueskyCredentials::buildCredentials('  @Alice.BSKY.social ', 'abcd-efgh-ijkl-mnop');

        self::assertSame(SocialNetwork::Bluesky, $credentials->network);
        self::assertSame('alice.bsky.social', $credentials->address);
        self::assertSame('mnop', $credentials->hint);
    }

    public function testABlueskyAppPasswordTypedWithSurroundingWhitespaceIsStoredTrimmed(): void
    {
        $credentials = BlueskyCredentials::buildCredentials('alice.bsky.social', " abcd-efgh-ijkl-mnop\n");

        self::assertSame('abcd-efgh-ijkl-mnop', $credentials->secret);
    }

    public function testABlueskyAppPasswordWithATrailingSpaceIsAccepted(): void
    {
        $credentials = BlueskyCredentials::buildCredentials('alice.bsky.social', 'abcd-efgh-ijkl-mnop ');

        self::assertSame('abcd-efgh-ijkl-mnop', $credentials->secret);
    }

    public function testABlueskyMainPasswordIsRefused(): void
    {
        $this->expectException(NotAnAppPasswordException::class);
        $this->expectExceptionMessage('Use an app password, not your account password.');

        BlueskyCredentials::buildCredentials('alice.bsky.social', 'hunter2hunter2');
    }

    public function testABlueskyDidIsKeptVerbatim(): void
    {
        self::assertSame(
            'did:plc:AbC123',
            BlueskyCredentials::buildCredentials('did:plc:AbC123', 'abcd-efgh-ijkl-mnop')->address,
        );
    }

    public function testReconnectingABlueskyAccountWithAMainPasswordIsRefused(): void
    {
        $account = SocialAccounts::blueskyAs(SocialAccounts::owner(), 'did:plc:abc123');

        $this->expectException(NotAnAppPasswordException::class);
        $this->expectExceptionMessage('Use an app password, not your account password.');

        BlueskyCredentials::buildReconnectCredentials($account, new CredentialReplacementModel('hunter2hunter2', null));
    }

    public function testAReconnectSecretTypedWithSurroundingWhitespaceIsTrimmed(): void
    {
        $account = SocialAccounts::blueskyAs(SocialAccounts::owner(), 'did:plc:abc123');

        $credentials = BlueskyCredentials::buildReconnectCredentials(
            $account,
            new CredentialReplacementModel("  abcd-efgh-ijkl-mnop \n", null),
        );

        self::assertSame('abcd-efgh-ijkl-mnop', $credentials->secret);
    }

    public function testReconnectingABlueskyAccountAddressesItByItsStoredIdentity(): void
    {
        $account = SocialAccounts::blueskyAs(SocialAccounts::owner(), 'did:plc:abc123');

        $credentials = BlueskyCredentials::buildReconnectCredentials(
            $account,
            new CredentialReplacementModel('abcd-efgh-ijkl-mnop', null),
        );

        self::assertSame(SocialNetwork::Bluesky, $credentials->network);
        self::assertSame('did:plc:abc123', $credentials->address);
        self::assertSame('abcd-efgh-ijkl-mnop', $credentials->secret);
        self::assertSame('mnop', $credentials->hint);
    }
}
