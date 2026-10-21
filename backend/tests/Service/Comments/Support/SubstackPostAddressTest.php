<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\FeedPlatform;
use App\Service\Comments\Support\SubstackPostAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubstackPostAddressTest extends TestCase
{
    private static function entry(?string $url, ?FeedPlatform $platform = FeedPlatform::Substack): Entry
    {
        $feed = new Feed('https://www.astralcodexten.com/feed');
        $feed->recordPlatform($platform);

        return new Entry($feed, 'guid', $url, 'Title', new \DateTimeImmutable(), new \DateTimeImmutable());
    }

    public function testAPostOfASubstackIsAddressedByItsOriginAndSlug(): void
    {
        $post = SubstackPostAddress::of(self::entry('https://www.astralcodexten.com/p/against-against-handover'));

        self::assertNotNull($post);
        self::assertSame('https://www.astralcodexten.com', $post->origin);
        self::assertSame('against-against-handover', $post->slug);
    }

    public function testATrailingSlashAndAQueryAreNoPartOfTheSlug(): void
    {
        $post = SubstackPostAddress::of(self::entry('https://joe.substack.com/p/one-post/?utm_source=rss'));

        self::assertSame('one-post', $post?->slug);
    }

    /** @return iterable<string, array{string|null}> */
    public static function notPosts(): iterable
    {
        yield 'no URL' => [null];
        yield 'a note' => ['https://joe.substack.com/note/c-123'];
        yield 'a podcast page' => ['https://joe.substack.com/podcast'];
        yield 'a comment' => ['https://joe.substack.com/p/one-post/comment/5'];
        yield 'plain http' => ['http://joe.substack.com/p/one-post'];
    }

    #[DataProvider('notPosts')]
    public function testAnythingButAPostPageIsNoPost(?string $url): void
    {
        self::assertNull(SubstackPostAddress::of(self::entry($url)));
    }

    public function testAPostPathOnAFeedThatIsNoSubstackIsNoPost(): void
    {
        self::assertNull(SubstackPostAddress::of(self::entry('https://blog.example.org/p/one-post', null)));
    }
}
