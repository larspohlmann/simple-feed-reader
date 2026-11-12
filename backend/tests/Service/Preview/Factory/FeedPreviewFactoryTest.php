<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview\Factory;

use App\Entity\Headline;
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
}
