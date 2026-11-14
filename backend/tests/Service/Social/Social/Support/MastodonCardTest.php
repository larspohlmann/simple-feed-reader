<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\MastodonCard;
use PHPUnit\Framework\TestCase;

final class MastodonCardTest extends TestCase
{
    public function testACardWithUrlAndTitleNamesItsProviderAsSite(): void
    {
        $card = MastodonCard::of(JsonNodeModel::of([
            'url' => 'https://example.com/a',
            'title' => 'A',
            'description' => 'D',
            'image' => 'https://example.com/i.png',
            'provider_name' => 'Example',
        ]));

        self::assertSame('https://example.com/a', $card?->url);
        self::assertSame('A', $card->title);
        self::assertSame('Example', $card->siteName);
    }

    public function testWithoutAProviderTheHostNamesTheSite(): void
    {
        $card = MastodonCard::of(JsonNodeModel::of(['url' => 'https://example.com/a', 'title' => 'A']));

        self::assertSame('example.com', $card?->siteName);
    }

    public function testACardWithoutATitleIsNoCard(): void
    {
        self::assertNull(MastodonCard::of(JsonNodeModel::of(['url' => 'https://example.com/a'])));
    }
}
