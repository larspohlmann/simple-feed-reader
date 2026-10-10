<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\EntryLine;
use PHPUnit\Framework\TestCase;

final class EntryLineTest extends TestCase
{
    /** @return array<string, mixed> */
    private function baseLine(): array
    {
        return [
            'feedUrl' => 'https://f/feed', 'guid' => 'g', 'guidHash' => str_repeat('a', 64),
            'url' => 'https://f/one', 'title' => 'One', 'author' => null, 'summary' => null,
            'contentHtml' => null, 'imageUrl' => null, 'imageWidth' => null, 'imageHeight' => null,
            'publishedAt' => null, 'createdAt' => '2026-09-07T00:00:00+00:00',
            'effectiveDate' => '2026-09-07T00:00:00+00:00',
        ];
    }

    public function testReadsMediaAndAttachments(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'media' => [['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800]],
            'attachments' => [['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg']],
        ]);

        self::assertSame([['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800]], $line->media);
        self::assertSame([['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg']], $line->attachments);
    }

    public function testDefaultsToEmptyWhenAnOlderFileOmitsTheKeys(): void
    {
        $line = EntryLine::fromLine($this->baseLine());

        self::assertSame([], $line->media);
        self::assertSame([], $line->attachments);
    }

    public function testReadsWhetherTheTitleWasDerived(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['titleDerived' => true]);

        self::assertTrue($line->titleDerived);
    }

    public function testAnOlderFileWithoutTitleDerivedReadsAsNotDerived(): void
    {
        self::assertFalse(EntryLine::fromLine($this->baseLine())->titleDerived);
    }

    public function testReadsTheImageRenditions(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'imageRenditions' => [['url' => 'https://i/lead-600.jpg', 'width' => 600]],
        ]);

        self::assertSame([['url' => 'https://i/lead-600.jpg', 'width' => 600]], $line->imageRenditions);
    }

    public function testAnOlderFileWithoutImageRenditionsReadsAsNone(): void
    {
        self::assertSame([], EntryLine::fromLine($this->baseLine())->imageRenditions);
    }
}
