<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Http\QuotedPostJson;
use PHPUnit\Framework\TestCase;

final class QuotedPostJsonTest extends TestCase
{
    public function testAQuoteIsPlainFieldsWithAnIsoUtcTime(): void
    {
        $json = QuotedPostJson::ofOrNull(new QuotedPostContent(
            'https://bsky.app/profile/q/post/3k',
            'Quoted Person',
            'quoted.bsky.social',
            null,
            new \DateTimeImmutable('2026-10-13 21:07:00', new \DateTimeZone('UTC')),
            "a\nb",
            [new EntryMedium('https://cdn.example/1.jpg', 'image', 1200, 675)],
            new QuotedPostReference('https://bsky.app/profile/n/post/9', null),
        ));

        self::assertSame([
            'url' => 'https://bsky.app/profile/q/post/3k',
            'authorName' => 'Quoted Person',
            'authorHandle' => 'quoted.bsky.social',
            'authorAvatarUrl' => null,
            'postedAt' => '2026-10-13T21:07:00+00:00',
            'text' => "a\nb",
            'media' => [['url' => 'https://cdn.example/1.jpg', 'kind' => 'image', 'width' => 1200, 'height' => 675]],
            'nestedQuote' => ['url' => 'https://bsky.app/profile/n/post/9', 'handle' => null],
        ], $json);
    }

    public function testNoQuoteIsNull(): void
    {
        self::assertNull(QuotedPostJson::ofOrNull(null));
    }

    public function testAQuoteWithoutTimeOrNestedQuoteSaysSo(): void
    {
        $quote = new QuotedPostContent('https://x.example/p/1', 'N', 'h', null, null, 't', [], null);
        $json = QuotedPostJson::ofOrNull($quote);

        self::assertNotNull($json);
        self::assertNull($json['postedAt']);
        self::assertNull($json['nestedQuote']);
    }
}
