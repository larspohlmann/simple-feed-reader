<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\LinkCardContent;
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

    public function testAnOlderFilesShareUrlIsIgnored(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'link', 'shareUrl' => 'https://f/one']);

        self::assertSame('link', $line->shareKind);
    }

    public function testReadsTheLinkCardsAndDropsOnesNoCardMayHold(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['linkCards' => [
            [
                'url' => 'https://example.com/a',
                'title' => 'A',
                'description' => 'About A',
                'imageUrl' => 'https://example.com/a.jpg',
                'siteName' => 'Example',
            ],
            ['url' => 'javascript:alert(1)', 'title' => 'X', 'siteName' => 'X'],
            ['url' => 'https://example.com/b', 'title' => 'B', 'imageUrl' => 'http://e.com/b.jpg', 'siteName' => 'E'],
        ]]);

        self::assertEquals([
            new LinkCardContent('https://example.com/a', 'A', 'About A', 'https://example.com/a.jpg', 'Example'),
            new LinkCardContent('https://example.com/b', 'B', null, null, 'E'),
        ], $line->linkCards);
    }

    public function testAnOlderFileWithoutLinkCardsReadsAsNone(): void
    {
        self::assertSame([], EntryLine::fromLine($this->baseLine())->linkCards);
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

    public function testAConstructedLineIsNotDerivedUnlessSaid(): void
    {
        $moment = new \DateTimeImmutable('2026-09-07T00:00:00+00:00');

        $line = new EntryLine(
            feedUrl: 'https://f/feed',
            guid: 'g',
            guidHash: str_repeat('a', 64),
            url: 'https://f/one',
            title: 'One',
            author: null,
            summary: null,
            contentHtml: null,
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
            publishedAt: null,
            createdAt: $moment,
            effectiveDate: $moment,
        );

        self::assertFalse($line->titleDerived);
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
