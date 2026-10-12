<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\MastodonAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonAccountTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, string}> */
    public static function profiles(): iterable
    {
        yield 'a local profile' => ['bob', 'https://mastodon.example/@bob', 'https://mastodon.example/@bob.rss'];
        yield 'a profile served from another host' => [
            'frank@example.com',
            'https://social.example.com/@frank',
            'https://social.example.com/@frank.rss',
        ];
        yield 'a profile over http' => [
            'eve@insecure.example',
            'http://web.insecure.example/@eve',
            'https://insecure.example/@eve.rss',
        ];
        yield 'a profile with a query' => [
            'carol@other.example',
            'https://web.other.example/@carol?lang=de',
            'https://other.example/@carol.rss',
        ];
        yield 'a profile with a fragment' => [
            'carol@other.example',
            'https://web.other.example/@carol#posts',
            'https://other.example/@carol.rss',
        ];
        yield 'a users path' => [
            'dave@pleroma.example',
            'https://web.pleroma.example/users/dave',
            'https://pleroma.example/@dave.rss',
        ];
        yield 'a profile without a host' => [
            'grace@other.example',
            'https:/@grace',
            'https://other.example/@grace.rss',
        ];
        yield 'no profile url' => ['heidi@other.example', null, 'https://other.example/@heidi.rss'];
    }

    #[DataProvider('profiles')]
    public function testTheFeedIsTheProfilesOwnOnlyForAPlainHttpsProfileUrl(
        string $accountAddress,
        ?string $profileUrl,
        string $feedUrl,
    ): void {
        $follow = MastodonAccount::follow(
            JsonNodeModel::of(['acct' => $accountAddress, 'url' => $profileUrl]),
            'mastodon.example',
        );

        self::assertSame($feedUrl, $follow?->feedUrl);
    }

    public function testALocalAccountTakesTheLowerCasedInstanceHost(): void
    {
        $follow = MastodonAccount::follow(JsonNodeModel::of(['acct' => 'bob']), 'Mastodon.Example');

        self::assertSame('bob@mastodon.example', $follow?->identity);
    }

    public function testAnAnswerWithoutAnAccountAddressIsNoFollow(): void
    {
        self::assertNull(MastodonAccount::follow(JsonNodeModel::of(['username' => 'nobody']), 'mastodon.example'));
    }
}
