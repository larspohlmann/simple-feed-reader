<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language\Support;

use App\Service\Curation\Trending\Support\HeadlineBody;
use App\Service\Ingestion\Language\Support\PreviewText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreviewTextTest extends TestCase
{
    #[DataProvider('cards')]
    public function testItIsWhatSharedArticlesGuessesOn(string $title, string $description): void
    {
        $body = HeadlineBody::body($description, HeadlineBody::BODY_LENGTH);

        self::assertSame($title . "\n" . $body, PreviewText::of($title, $description));
    }

    /** @return iterable<string, array{string, string}> */
    public static function cards(): iterable
    {
        yield 'plain' => ['A title', 'A plain description of the page.'];
        yield 'markup' => ['Markup', '<p>First <b>bold</b> paragraph.</p><p>Second <img src="x.jpg">one.</p>'];
        yield 'long' => ['Long', str_repeat('A sentence of several words keeps going. ', 17)];
    }

    public function testTheTextIsTheTitleJoinedToTheDescriptionText(): void
    {
        $description = '<p>Some <i>marked</i> words</p>';

        self::assertSame(
            PreviewText::of('T', $description),
            PreviewText::joined('T', PreviewText::descriptionOf($description)),
        );
        self::assertSame('Some marked words', PreviewText::descriptionOf($description));
    }

    public function testANullDescriptionLeavesTheTitleAndANewline(): void
    {
        self::assertSame("Only a title\n", PreviewText::of('Only a title', null));
    }

    public function testTheDescriptionIsCutAtAWordBoundary(): void
    {
        $text = PreviewText::of('T', str_repeat('word ', 100));

        self::assertSame("T\n" . rtrim(str_repeat('word ', 60)), $text);
    }
}
