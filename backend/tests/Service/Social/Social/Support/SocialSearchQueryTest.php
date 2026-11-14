<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\SocialSearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialSearchQueryTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'trimmed' => ["  rss reader \n", 'rss reader'];
        yield 'inner whitespace collapsed' => ["rss \t  reader", 'rss reader'];
        yield 'case and operators kept' => [
            '("RSS Reader" || feedreader) -Gazette',
            '("RSS Reader" || feedreader) -Gazette',
        ];
        yield 'non-breaking space is whitespace' => ["rss\u{00A0}reader", 'rss reader'];
    }

    #[DataProvider('queries')]
    public function testAQueryIsTrimmedAndItsWhitespaceCollapsed(string $typed, string $normalized): void
    {
        self::assertSame($normalized, SocialSearchQuery::normalized($typed));
    }
}
