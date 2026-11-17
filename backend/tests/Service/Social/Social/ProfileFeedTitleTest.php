<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Social\Social\ProfileFeedTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileFeedTitleTest extends TestCase
{
    private const string BLUESKY_PROFILE = 'https://bsky.app/profile/motherjones.com/rss';
    private const string LEMMY_COMMUNITY = 'https://lemmy.world/feeds/c/simplefeedreader.xml';

    public function testABlueskyProfileFeedKeepsOnlyTheDisplayName(): void
    {
        $title = (new ProfileFeedTitle())->of(self::BLUESKY_PROFILE, '@motherjones.com - Mother Jones');

        self::assertSame('Mother Jones', $title);
    }

    public function testAProfileFollowedByDidKeepsOnlyTheDisplayName(): void
    {
        $url = 'https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/rss';

        self::assertSame('Mother Jones', (new ProfileFeedTitle())->of($url, '@motherjones.com - Mother Jones'));
    }

    public function testADisplayNameContainingTheSeparatorKeepsAllOfIt(): void
    {
        self::assertSame(
            'Mother Jones - Investigations',
            (new ProfileFeedTitle())->of(self::BLUESKY_PROFILE, '@motherjones.com - Mother Jones - Investigations'),
        );
    }

    public function testTheDisplayNameLosesTheSpaceAroundIt(): void
    {
        $title = (new ProfileFeedTitle())->of(self::BLUESKY_PROFILE, '@motherjones.com -  Mother Jones ');

        self::assertSame('Mother Jones', $title);
    }

    public function testALemmyCommunityFeedNamesTheCommunityBeforeTheSite(): void
    {
        $title = (new ProfileFeedTitle())->of(self::LEMMY_COMMUNITY, 'Lemmy.World - simplefeedreader');

        self::assertSame('simplefeedreader - Lemmy.World', $title);
    }

    public function testASiteNameContainingTheSeparatorStaysWhole(): void
    {
        $title = (new ProfileFeedTitle())->of(self::LEMMY_COMMUNITY, 'Lemmy - The World - simplefeedreader');

        self::assertSame('simplefeedreader - Lemmy - The World', $title);
    }

    /** @return iterable<string, array{string, string}> */
    public static function untouchedTitles(): iterable
    {
        yield 'a handle without a display name' => [self::BLUESKY_PROFILE, '@motherjones.com'];
        yield 'a blank display name' => [self::BLUESKY_PROFILE, '@motherjones.com -  '];
        yield 'a title not led by a handle' => [self::BLUESKY_PROFILE, 'Mother Jones - Politics'];
        yield 'a Mastodon profile feed' => ['https://mastodon.social/@Gargron.rss', '@Gargron - Eugen Rochko'];
        yield 'a Lemmy title without a site' => [self::LEMMY_COMMUNITY, 'simplefeedreader'];
        yield 'a Lemmy title with a blank site' => [self::LEMMY_COMMUNITY, ' - simplefeedreader'];
        yield 'a Lemmy title ending in another community' => [self::LEMMY_COMMUNITY, 'Lemmy.World - technology'];
        yield 'a feed that is no profile feed' => ['https://example.com/feed', '@example - Example'];
    }

    #[DataProvider('untouchedTitles')]
    public function testAnyOtherTitleStaysAsItIs(string $feedUrl, string $title): void
    {
        self::assertSame($title, (new ProfileFeedTitle())->of($feedUrl, $title));
    }
}
