<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Service\Social\Lemmy\Support\LemmySearchedPost;
use App\Service\Social\Social\Support\SocialSearchMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmySearchedPostTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function queries(): iterable
    {
        yield 'a word in the title matches' => ['rss', true];
        yield 'a word in the body matches' => ['atom', true];
        yield 'words may come from title and body' => ['atom rss', true];
        yield 'a phrase never spans title and body' => ['"compared atom"', false];
        yield 'a substring inside a word does not match' => ['eader', false];
        yield 'the author goes by name' => ['rss -from:ada', false];
        yield 'the author goes by address' => ['rss -from:ada@lemmy.example', false];
        yield 'another author is not excluded' => ['rss -from:bob', true];
    }

    #[DataProvider('queries')]
    public function testAPostIsJudgedByTitleBodyAndAuthor(string $query, bool $expected): void
    {
        $post = LemmySearchedPost::of('RSS readers compared', '<p>Atom too</p>', 'https://Lemmy.Example/u/ada');

        self::assertSame($expected, SocialSearchMatch::matcher($query)($post));
    }

    public function testAPostWithoutAnAuthorUrlHasNoNames(): void
    {
        self::assertTrue(SocialSearchMatch::matcher('rss -from:ada')(LemmySearchedPost::of('rss', null, null)));
    }

    public function testAnAuthorUrlWithoutAUserPathHasNoNames(): void
    {
        $post = LemmySearchedPost::of('rss', null, 'https://lemmy.example/c/news');

        self::assertTrue(SocialSearchMatch::matcher('rss -from:news')($post));
    }
}
