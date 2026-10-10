<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Model;

use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedTitleModel;
use App\Entity\Discussion;
use App\Service\Image\Model\DeclaredImageModel;
use PHPUnit\Framework\TestCase;

final class ParsedEntryModelTest extends TestCase
{
    public function testCategoriesDefaultToEmptyList(): void
    {
        $entry = new ParsedEntryModel('guid', null, 'Title', null, null, null, null);

        self::assertSame([], $entry->categories);
    }

    public function testCategoriesArePreserved(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            null,
            'Title',
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

    public function testTheDerivedTitleFlagSurvivesEveryCopy(): void
    {
        $entry = new ParsedEntryModel('guid', null, 'Title', null, null, null, null, titleDerived: true);

        self::assertTrue($entry->withContentHtml('<p>x</p>')->titleDerived);
        self::assertTrue($entry->asDiscussionThread(Discussion::none())->titleDerived);
        self::assertTrue($entry->withShowArtwork(new DeclaredImageModel('https://example.com/a.jpg'))->titleDerived);
    }

    public function testWithPostTextReplacesTitleSummaryAndContentAndKeepsTheRest(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            'https://example.com/1',
            'Old title',
            'Author',
            'Old summary',
            '<p>Old</p>',
            null,
            categories: [new ParsedCategoryModel('Politics', 'https://example.test/tax')],
            authorUrl: 'https://example.com/author',
        );

        $copy = $entry->withPostText(ParsedTitleModel::derived('New title'), null, '<p>New</p>');

        self::assertSame('New title', $copy->title);
        self::assertTrue($copy->titleDerived);
        self::assertNull($copy->summary);
        self::assertSame('<p>New</p>', $copy->contentHtml);
        self::assertSame('https://example.com/1', $copy->url);
        self::assertSame('Author', $copy->author);
        self::assertSame($entry->categories, $copy->categories);
        self::assertSame('https://example.com/author', $copy->authorUrl);
        self::assertSame('Old summary', $entry->withContentHtml('<p>x</p>')->summary);
    }

    public function testParsedTitleSaysWhetherTheTitleWasDerived(): void
    {
        $derived = (new ParsedEntryModel('guid', null, 'Post text', null, null, null, null, titleDerived: true))
            ->parsedTitle();
        $fromFeed = (new ParsedEntryModel('guid', null, 'Headline', null, null, null, null))->parsedTitle();

        self::assertSame(['Post text', true], [$derived->text, $derived->derived]);
        self::assertSame(['Headline', false], [$fromFeed->text, $fromFeed->derived]);
    }
}
