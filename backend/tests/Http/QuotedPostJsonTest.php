<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Entity\LinkCardContent;
use App\Tests\Support\JsonMappers;
use PHPUnit\Framework\TestCase;

final class QuotedPostJsonTest extends TestCase
{
    public function testAQuoteIsPlainFieldsWithAnIsoUtcTime(): void
    {
        $json = JsonMappers::quotedPostJson()->ofOrNull(new QuotedPostContent(
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
            'linkCard' => null,
        ], $json);
    }

    public function testNoQuoteIsNull(): void
    {
        self::assertNull(JsonMappers::quotedPostJson()->ofOrNull(null));
    }

    public function testAQuoteWithoutTimeOrNestedQuoteSaysSo(): void
    {
        $quote = new QuotedPostContent('https://x.example/p/1', 'N', 'h', null, null, 't', [], null);
        $json = JsonMappers::quotedPostJson()->ofOrNull($quote);

        self::assertNotNull($json);
        self::assertNull($json['postedAt']);
        self::assertNull($json['nestedQuote']);
    }

    public function testAQuotesCardCarriesTheTokenThatOpensItInTheReader(): void
    {
        $card = new LinkCardContent('https://n.example/s', 'Story', 'D', 'https://n.example/s.jpg', 'n.example');
        $quote = new QuotedPostContent('https://x.example/p/1', 'N', 'h', null, null, 't', [], null, $card);

        self::assertSame(
            [
                'url' => 'https://n.example/s',
                'title' => 'Story',
                'description' => 'D',
                'imageUrl' => 'https://n.example/s.jpg',
                'siteName' => 'n.example',
                'readerToken' => JsonMappers::readerToken('https://n.example/s'),
            ],
            JsonMappers::quotedPostJson()->ofOrNull($quote)['linkCard'] ?? null,
        );
    }
}
