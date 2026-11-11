<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory\Support;

use App\Service\FeedDirectory\Support\FeedDirectoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryUrlTest extends TestCase
{
    private const string CASES = __DIR__
        . '/../../../../../frontend/src/app/settings/feed-directory/feed-directory-url-cases.json';

    /** @return iterable<string, array{string, string, string}> */
    public static function sharedCases(): iterable
    {
        $cases = json_decode((string) file_get_contents(self::CASES), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($cases)) {
            throw new \UnexpectedValueException('The shared URL case table is not a list.');
        }
        foreach ($cases as $case) {
            if (
                !\is_array($case)
                || !\is_string($case['url'] ?? null)
                || !\is_string($case['normalised'] ?? null)
                || !\is_string($case['host'] ?? null)
            ) {
                throw new \UnexpectedValueException('A shared URL case lacks url, normalised or host.');
            }
            yield $case['url'] => [$case['url'], $case['normalised'], $case['host']];
        }
    }

    #[DataProvider('sharedCases')]
    public function testItNormalisesLikeTheFrontend(string $url, string $normalised, string $host): void
    {
        self::assertSame($normalised, FeedDirectoryUrl::normalise($url));
        self::assertSame($host, FeedDirectoryUrl::host($url));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function paddedUrls(): iterable
    {
        yield 'spaces' => ['  https://www.Example.com/feed/  ', 'example.com/feed', 'example.com'];
        yield 'line breaks' => ["\nhttps://example.com:8080/rss?x=1\r\n", 'example.com:8080/rss?x=1', 'example.com'];
    }

    #[DataProvider('paddedUrls')]
    public function testWhitespaceAroundAUrlIsIgnored(string $url, string $normalised, string $host): void
    {
        self::assertSame($normalised, FeedDirectoryUrl::normalise($url));
        self::assertSame($host, FeedDirectoryUrl::host($url));
    }
}
