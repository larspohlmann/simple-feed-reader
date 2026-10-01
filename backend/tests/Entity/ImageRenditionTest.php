<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;
use App\Entity\ImageRendition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageRenditionTest extends TestCase
{
    public function testALadderKeepsTheFirstRenditionOfEachUrlNarrowestFirst(): void
    {
        $ladder = ImageRendition::ladder([
            new ImageRendition('https://i/a-1024.jpg', 1024),
            new ImageRendition('https://i/a-300.jpg', 300),
            new ImageRendition('https://i/a-1024.jpg', 696),
        ]);

        self::assertEquals(
            [new ImageRendition('https://i/a-300.jpg', 300), new ImageRendition('https://i/a-1024.jpg', 1024)],
            $ladder,
        );
    }

    public function testAStoredRenditionReadsBackAsWritten(): void
    {
        $rendition = new ImageRendition('https://i/a.jpg', 640);

        self::assertEquals($rendition, ImageRendition::fromStored($rendition->jsonSerialize()));
    }

    public function testTheJsonListCarriesUrlAndWidth(): void
    {
        self::assertSame(
            [['url' => 'https://i/a.jpg', 'width' => 640]],
            ImageRendition::toJsonList([new ImageRendition('https://i/a.jpg', 640)]),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteStoredRenditions(): iterable
    {
        yield 'no url' => [['width' => 640]];
        yield 'no width' => [['url' => 'https://i/a.jpg']];
        yield 'a zero width' => [['url' => 'https://i/a.jpg', 'width' => 0]];
        yield 'a string width' => [['url' => 'https://i/a.jpg', 'width' => '640']];
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('incompleteStoredRenditions')]
    public function testAnIncompleteStoredRenditionIsRefused(array $stored): void
    {
        self::assertFalse(ImageRendition::isComplete($stored));

        $this->expectException(IncompleteStoredMediaException::class);

        ImageRendition::fromStored($stored);
    }
}
