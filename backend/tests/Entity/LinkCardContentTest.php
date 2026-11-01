<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LinkCardContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkCardContentTest extends TestCase
{
    public function testAStoredCardReadsBackWhole(): void
    {
        $card = new LinkCardContent(
            'https://n.example/s',
            'Story',
            'What happened.',
            'https://n.example/s.jpg',
            'News',
        );

        self::assertSame(
            [
                'url' => 'https://n.example/s',
                'title' => 'Story',
                'description' => 'What happened.',
                'imageUrl' => 'https://n.example/s.jpg',
                'siteName' => 'News',
            ],
            $card->toStored(),
        );
        self::assertEquals($card, LinkCardContent::fromStoredOrNull($card->toStored()));
    }

    public function testNothingStoredIsNoCard(): void
    {
        self::assertNull(LinkCardContent::fromStoredOrNull(null));
    }

    /** @return iterable<string, array{string}> */
    public static function requiredKeys(): iterable
    {
        yield 'url' => ['url'];
        yield 'title' => ['title'];
        yield 'site name' => ['siteName'];
    }

    #[DataProvider('requiredKeys')]
    public function testAStoredCardMissingARequiredKeyIsNoCard(string $missing): void
    {
        $stored = (new LinkCardContent('https://n.example/s', 'Story', null, null, 'News'))->toStored();
        unset($stored[$missing]);

        self::assertNull(LinkCardContent::fromStoredOrNull($stored));
    }

    public function testOptionalFieldsOfTheWrongTypeReadAsNone(): void
    {
        $card = LinkCardContent::fromStoredOrNull(
            [
                'url' => 'https://n.example/s',
                'title' => 'Story',
                'siteName' => 'News',
                'description' => 3,
                'imageUrl' => [],
            ],
        );

        self::assertEquals(new LinkCardContent('https://n.example/s', 'Story', null, null, 'News'), $card);
    }
}
