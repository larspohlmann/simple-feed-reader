<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Model;

use App\Entity\Headline;
use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\TestCase;

final class ParsedEntryModelTest extends TestCase
{
    public function testCategoriesDefaultToEmptyList(): void
    {
        $entry = new ParsedEntryModel('guid', null, Headline::fromFeed('Title'), null, null, null, null);

        self::assertSame([], $entry->categories);
    }

    public function testCategoriesArePreserved(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            null,
            Headline::fromFeed('Title'),
            null,
            null,
            null,
            null,
            categories: [new ParsedCategoryModel('Politics', 'https://example.test/tax')],
        );

        self::assertCount(1, $entry->categories);
        self::assertSame('Politics', $entry->categories[0]->label);
        self::assertSame('https://example.test/tax', $entry->categories[0]->scheme);
    }

    public function testWithPostTextReplacesTitleSummaryAndContentAndKeepsTheRest(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            'https://example.com/1',
            Headline::fromFeed('Old title'),
            'Author',
            'Old summary',
            '<p>Old</p>',
            null,
            categories: [new ParsedCategoryModel('Politics', 'https://example.test/tax')],
            authorUrl: 'https://example.com/author',
        );

        $copy = $entry->withPostText(Headline::derived('New title'), null, '<p>New</p>');

        self::assertEquals(Headline::derived('New title'), $copy->headline);
        self::assertNull($copy->summary);
        self::assertSame('<p>New</p>', $copy->contentHtml);
        self::assertSame('https://example.com/1', $copy->url);
        self::assertSame('Author', $copy->author);
        self::assertSame($entry->categories, $copy->categories);
        self::assertSame('https://example.com/author', $copy->authorUrl);
        self::assertSame('Old summary', $entry->withContentHtml('<p>x</p>')->summary);
    }
}
