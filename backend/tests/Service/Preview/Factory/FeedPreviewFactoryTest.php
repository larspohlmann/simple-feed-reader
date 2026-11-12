<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview\Factory;

use App\Entity\Headline;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Preview\Factory\FeedPreviewFactory;
use PHPUnit\Framework\TestCase;

final class FeedPreviewFactoryTest extends TestCase
{
    public function testSummarizesADocumentItIsHanded(): void
    {
        $entry = new ParsedEntryModel(
            guid: 'https://example.com/1',
            url: 'https://example.com/1',
            headline: Headline::fromFeed('First post'),
            author: 'A. Writer',
            summary: 'A short teaser.',
            contentHtml: null,
            publishedAt: new \DateTimeImmutable('2026-07-01T10:00:00Z'),
        );

        $preview = new FeedPreviewFactory()->fromDocument(
            new ParsedFeedModel('Example Feed', null, null, null, [$entry]),
        );

        self::assertSame('Example Feed', $preview->title);
        self::assertSame(1, $preview->itemCount);
        self::assertSame('summary', $preview->content);
        self::assertFalse($preview->hasImages);
        self::assertCount(1, $preview->items);
        self::assertSame('First post', $preview->items[0]->title);
        self::assertSame('A. Writer', $preview->items[0]->author);
        self::assertSame('A short teaser.', $preview->items[0]->summary);
    }

    public function testAnEmptyFeedReadsAsTitleOnly(): void
    {
        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Empty', null, null, null, []));

        self::assertSame('title-only', $preview->content);
        self::assertSame(0, $preview->itemCount);
        self::assertSame([], $preview->items);
    }

    public function testOnlyTheFirstEightEntriesDecideTheVerdictAndTheImageBadge(): void
    {
        $plain = array_map(fn (int $number): ParsedEntryModel => $this->entry($number), range(1, 8));
        $lateEntry = $this->entry(
            9,
            summary: null,
            contentHtml: str_repeat('x', 700),
            image: 'https://example.com/9.jpg',
        );
        $lateEntries = array_fill(0, 9, $lateEntry);

        $preview = new FeedPreviewFactory()->fromDocument(
            new ParsedFeedModel('Long', null, null, null, [...$plain, ...$lateEntries]),
        );

        self::assertSame('summary', $preview->content);
        self::assertFalse($preview->hasImages);
    }

    public function testAtMostTenEntriesAreShown(): void
    {
        $entries = array_map(fn (int $number): ParsedEntryModel => $this->entry($number), range(1, 12));

        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Long', null, null, null, $entries));

        self::assertCount(10, $preview->items);
        self::assertSame(12, $preview->itemCount);
    }

    public function testAFeedWithFewerEntriesShowsThemAll(): void
    {
        $entries = array_map(fn (int $number): ParsedEntryModel => $this->entry($number), range(1, 4));

        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Short', null, null, null, $entries));

        self::assertCount(4, $preview->items);
    }

    public function testATieBetweenTiersGoesToTheRicherOne(): void
    {
        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Tie', null, null, null, [
            $this->entry(1, summary: null, contentHtml: str_repeat('x', 700)),
            $this->entry(2),
        ]));

        self::assertSame('full', $preview->content);
    }

    public function testABodyOfExactlySixHundredCharactersIsFullText(): void
    {
        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Full', null, null, null, [
            $this->entry(1, summary: null, contentHtml: str_repeat('x', 600)),
        ]));

        self::assertSame('full', $preview->content);
    }

    public function testBodyLengthCountsCharactersNotBytes(): void
    {
        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Umlauts', null, null, null, [
            $this->entry(1, summary: null, contentHtml: str_repeat("\u{e4}", 400)),
        ]));

        self::assertSame('summary', $preview->content);
    }

    public function testAnItemCarriesItsHttpsImageWithDimensionsAndDropsOthers(): void
    {
        $preview = new FeedPreviewFactory()->fromDocument(new ParsedFeedModel('Pictures', null, null, null, [
            $this->entry(1, image: 'https://example.com/1.jpg', width: 640, height: 360),
            $this->entry(2, image: 'http://example.com/2.jpg', width: 640, height: 360),
            $this->entry(3),
        ]));

        self::assertTrue($preview->hasImages);
        self::assertSame('https://example.com/1.jpg', $preview->items[0]->imageUrl);
        self::assertSame(640, $preview->items[0]->imageWidth);
        self::assertSame(360, $preview->items[0]->imageHeight);
        self::assertNull($preview->items[1]->imageUrl);
        self::assertNull($preview->items[1]->imageWidth);
        self::assertNull($preview->items[1]->imageHeight);
        self::assertNull($preview->items[2]->imageUrl);
    }

    private function entry(
        int $number,
        ?string $summary = 'A teaser.',
        ?string $contentHtml = null,
        ?string $image = null,
        ?int $width = null,
        ?int $height = null,
    ): ParsedEntryModel {
        return new ParsedEntryModel(
            guid: 'https://example.com/' . $number,
            url: 'https://example.com/' . $number,
            headline: Headline::fromFeed('Post ' . $number),
            author: null,
            summary: $summary,
            contentHtml: $contentHtml,
            publishedAt: new \DateTimeImmutable('2026-07-01T10:00:00Z'),
            media: new ParsedEntryMediaModel(null === $image ? null : new DeclaredImageModel($image, $width, $height)),
        );
    }
}
