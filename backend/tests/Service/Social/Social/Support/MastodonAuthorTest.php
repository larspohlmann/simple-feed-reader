<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\MastodonAuthor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonAuthorTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function names(): iterable
    {
        yield 'a display name' => [['display_name' => 'Ann', 'acct' => 'ann'], 'Ann'];
        yield 'an empty display name' => [['display_name' => '', 'acct' => 'ann'], 'ann'];
        yield 'no display name' => [['acct' => 'ann@hachyderm.io'], 'ann@hachyderm.io'];
        yield 'neither' => [[], null];
    }

    /** @param array<string, string> $account */
    #[DataProvider('names')]
    public function testTheNameFallsBackToTheAccountAddress(array $account, ?string $name): void
    {
        self::assertSame($name, MastodonAuthor::name(JsonNodeModel::of($account)));
    }

    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function handles(): iterable
    {
        yield 'a local account' => [['acct' => 'ann', 'url' => 'https://hachyderm.io/@ann'], 'ann@hachyderm.io'];
        yield 'a remote account' => [
            ['acct' => 'bo@fosstodon.org', 'url' => 'https://hachyderm.io/@bo'],
            'bo@fosstodon.org',
        ];
        yield 'a local account without a profile' => [['acct' => 'ann'], 'ann'];
        yield 'no account' => [['url' => 'https://hachyderm.io/@ann'], null];
    }

    /** @param array<string, string> $account */
    #[DataProvider('handles')]
    public function testTheHandleIsCompletedWithTheProfileHostWhenLocal(array $account, ?string $handle): void
    {
        self::assertSame($handle, MastodonAuthor::handle(JsonNodeModel::of($account)));
    }

    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function avatars(): iterable
    {
        yield 'static preferred' => [
            ['avatar_static' => 'https://a.test/s.png', 'avatar' => 'https://a.test/a.gif'],
            'https://a.test/s.png',
        ];
        yield 'animated fallback' => [['avatar' => 'https://a.test/a.gif'], 'https://a.test/a.gif'];
        yield 'not absolute' => [['avatar_static' => '/s.png', 'avatar' => '/a.gif'], null];
    }

    /** @param array<string, string> $account */
    #[DataProvider('avatars')]
    public function testTheAvatarPrefersTheStaticImage(array $account, ?string $avatar): void
    {
        self::assertSame($avatar, MastodonAuthor::avatar(JsonNodeModel::of($account)));
    }

    public function testTheProfileIsAnAbsoluteHttpUrlOnly(): void
    {
        $profile = MastodonAuthor::profile(JsonNodeModel::of(['url' => 'https://h.test/@ann']));

        self::assertSame('https://h.test/@ann', $profile);
        self::assertNull(MastodonAuthor::profile(JsonNodeModel::of(['url' => '/@ann'])));
    }

    public function testAProfileUrlNamesTheSameIdentityAsTheAccountItShows(): void
    {
        $account = JsonNodeModel::of(['acct' => 'ZOË', 'url' => 'https://Mastodon.Example/@ZOË']);

        self::assertSame('zoë@mastodon.example', MastodonAuthor::identity($account));
        self::assertSame(
            MastodonAuthor::identity($account),
            MastodonAuthor::identityOfProfileUrl('https://Mastodon.Example/@ZOË'),
        );
    }
}
