<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Model;

use App\Entity\EntryChapter;
use App\Service\Chapter\Model\EmbedDetailsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmbedDetailsModelTest extends TestCase
{
    public function testTheCacheEntryNamesEveryField(): void
    {
        $details = new EmbedDetailsModel('A mix', 4964, [new EntryChapter(0, 'Intro')]);

        self::assertSame(
            ['title' => 'A mix', 'durationSeconds' => 4964, 'chapters' => [['startSeconds' => 0, 'title' => 'Intro']]],
            $details->toCacheEntry(),
        );
    }

    #[DataProvider('roundTrips')]
    public function testACacheEntryReadsBackEqual(EmbedDetailsModel $details): void
    {
        self::assertEquals($details, EmbedDetailsModel::fromCacheEntryOrNull($details->toCacheEntry()));
    }

    /** @return iterable<string, array{EmbedDetailsModel}> */
    public static function roundTrips(): iterable
    {
        $chapters = [new EntryChapter(0, 'A'), new EntryChapter(9, 'B')];
        yield 'everything' => [new EmbedDetailsModel('A mix', 4964, $chapters)];
        yield 'nothing known' => [new EmbedDetailsModel(null, null, [])];
        yield 'a title only' => [new EmbedDetailsModel('A track', null, [])];
    }

    #[DataProvider('malformedEntries')]
    public function testAMalformedEntryReadsAsNull(mixed $stored): void
    {
        self::assertNull(EmbedDetailsModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedEntries(): iterable
    {
        $chapters = [['startSeconds' => 0, 'title' => 'Intro']];
        yield 'not an array' => ['garbage'];
        yield 'a bare chapter list' => [$chapters];
        yield 'no chapters key' => [['title' => 'x', 'durationSeconds' => 1]];
        yield 'no title key' => [['durationSeconds' => 1, 'chapters' => $chapters]];
        yield 'no length key' => [['title' => 'x', 'chapters' => $chapters]];
        yield 'only chapters' => [['chapters' => []]];
        yield 'a length of zero' => [['title' => 'x', 'durationSeconds' => 0, 'chapters' => $chapters]];
        yield 'a negative length' => [['title' => 'x', 'durationSeconds' => -5, 'chapters' => $chapters]];
        yield 'a title not a string' => [['title' => 5, 'durationSeconds' => 1, 'chapters' => $chapters]];
        yield 'a length not an int' => [['title' => 'x', 'durationSeconds' => '1', 'chapters' => $chapters]];
        yield 'chapters not a list' => [['title' => 'x', 'durationSeconds' => 1, 'chapters' => ['a' => $chapters[0]]]];
        yield 'an incomplete chapter' => [['title' => 'x', 'durationSeconds' => 1, 'chapters' => [['title' => 'x']]]];
        yield 'a chapter not an object' => [['title' => 'x', 'durationSeconds' => 1, 'chapters' => ['x']]];
        yield 'a chapter as a list' => [['title' => 'x', 'durationSeconds' => 1, 'chapters' => [[0, 'Intro']]]];
    }
}
