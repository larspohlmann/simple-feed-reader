<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Model\SocialCredentialsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialCredentialsModelTest extends TestCase
{
    public function testABlueskyHandleIsTrimmedLowercasedAndLosesItsAt(): void
    {
        $credentials = SocialCredentialsModel::bluesky('  @Alice.BSKY.social ', 'abcd-efgh-ijkl-mnop');

        self::assertSame(SocialNetwork::Bluesky, $credentials->network);
        self::assertSame('alice.bsky.social', $credentials->address);
        self::assertSame('mnop', $credentials->hint());
    }

    public function testABlueskyDidIsKeptVerbatim(): void
    {
        self::assertSame(
            'did:plc:AbC123',
            SocialCredentialsModel::bluesky('did:plc:AbC123', 'abcd-efgh-ijkl-mnop')->address,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function instanceUrls(): iterable
    {
        yield 'bare host' => ['mastodon.social', 'https://mastodon.social'];
        yield 'trailing slash and path' => ['https://Mastodon.Social/@lars/', 'https://mastodon.social'];
        yield 'port kept' => ['https://social.example.test:8443', 'https://social.example.test:8443'];
    }

    #[DataProvider('instanceUrls')]
    public function testAMastodonInstanceUrlIsNormalised(string $typed, string $expected): void
    {
        self::assertSame($expected, SocialCredentialsModel::mastodon($typed, 'token-1234')->address);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedInstanceUrls(): iterable
    {
        yield 'plain http' => ['http://mastodon.social'];
        yield 'credentials' => ['https://user:pass@mastodon.social'];
        yield 'no host' => ['https://'];
    }

    #[DataProvider('refusedInstanceUrls')]
    public function testAnUnsafeInstanceUrlIsRefused(string $typed): void
    {
        $this->expectException(InvalidInstanceUrlException::class);

        SocialCredentialsModel::mastodon($typed, 'token-1234');
    }

    public function testReconnectingABlueskyAccountAddressesItByItsStoredIdentity(): void
    {
        $account = $this->account(SocialNetwork::Bluesky, 'did:plc:abc123', '');

        $credentials = SocialCredentialsModel::forReconnect($account, 'abcd-efgh-ijkl-mnop');

        self::assertSame(SocialNetwork::Bluesky, $credentials->network);
        self::assertSame('did:plc:abc123', $credentials->address);
        self::assertSame('abcd-efgh-ijkl-mnop', $credentials->secret);
    }

    public function testReconnectingAMastodonAccountAddressesItByItsStoredServiceUrl(): void
    {
        $account = $this->account(
            SocialNetwork::Mastodon,
            'https://mastodon.social/users/lars',
            'https://mastodon.social',
        );

        $credentials = SocialCredentialsModel::forReconnect($account, 'token-1234');

        self::assertSame(SocialNetwork::Mastodon, $credentials->network);
        self::assertSame('https://mastodon.social', $credentials->address);
    }

    private function account(SocialNetwork $network, string $identity, string $serviceUrl): SocialAccount
    {
        $now = new \DateTimeImmutable();

        return new SocialAccount(
            new User('lars@example.test', $now),
            $network,
            $identity,
            'lars',
            $serviceUrl,
            new SealedSecret('ciphertext', 'nonce', 'salt', 1),
            '1234',
            $now,
        );
    }
}
