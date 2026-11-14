<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Support;

use App\Service\Url\Support\LemmyPostUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyPostUrlTest extends TestCase
{
    public function testReadsTheHostAndPostId(): void
    {
        $post = LemmyPostUrl::parse('https://Lemmy.WORLD/post/54385737/');

        self::assertNotNull($post);
        self::assertSame('lemmy.world', $post->host);
        self::assertSame(54385737, $post->postId);
    }

    #[DataProvider('notPostUrls')]
    public function testRejectsWhatIsNotAPostPage(?string $url): void
    {
        self::assertNull(LemmyPostUrl::parse($url));
    }

    /** @return iterable<string, array{?string}> */
    public static function notPostUrls(): iterable
    {
        yield 'null' => [null];
        yield 'plain http' => ['http://lemmy.world/post/1'];
        yield 'a community' => ['https://lemmy.world/c/politics'];
        yield 'a comment' => ['https://lemmy.world/comment/5'];
        yield 'a port' => ['https://h.example:8080/post/1'];
        yield 'userinfo' => ['https://u@h.example/post/1'];
        yield 'a query' => ['https://h.example/post/1?x=1'];
        yield 'a query on the host' => ['https://h.example?x/post/1'];
        yield 'a fragment on the host' => ['https://h.example#x/post/1'];
        yield 'a longer path' => ['https://h.example/post/1/comment'];
    }
}
