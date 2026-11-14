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
        $details = new EmbedDetailsModel('A mix', 4964, [new EntryChapter(0, 'Intro')], 'https://art.test/a.jpg');

        self::assertSame(
            [
                'title' => 'A mix',
                'durationSeconds' => 4964,
                'chapters' => [['startSeconds' => 0, 'title' => 'Intro']],
                'artworkUrl' => 'https://art.test/a.jpg',
            ],
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
        yield 'everything' => [new EmbedDetailsModel('A mix', 4964, $chapters, 'https://art.test/a.jpg')];
        yield 'nothing known' => [new EmbedDetailsModel(null, null, [], null)];
        yield 'a title only' => [new EmbedDetailsModel('A track', null, [], null)];
        yield 'artwork only' => [new EmbedDetailsModel(null, null, [], 'https://art.test/a.jpg')];
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
        $valid = ['title' => 'x', 'durationSeconds' => 1, 'chapters' => $chapters, 'artworkUrl' => null];
        yield 'not an array' => ['garbage'];
        yield 'a bare chapter list' => [$chapters];
        yield 'no chapters key' => [array_diff_key($valid, ['chapters' => true])];
        yield 'no title key' => [array_diff_key($valid, ['title' => true])];
        yield 'no length key' => [array_diff_key($valid, ['durationSeconds' => true])];
        yield 'no artwork key, as cached before #1831' => [array_diff_key($valid, ['artworkUrl' => true])];
        yield 'only chapters' => [['chapters' => []]];
        yield 'a length of zero' => [['durationSeconds' => 0] + $valid];
        yield 'a negative length' => [['durationSeconds' => -5] + $valid];
        yield 'a title not a string' => [['title' => 5] + $valid];
        yield 'a length not an int' => [['durationSeconds' => '1'] + $valid];
        yield 'artwork not a string' => [['artworkUrl' => 5] + $valid];
        yield 'chapters not a list' => [['chapters' => ['a' => $chapters[0]]] + $valid];
        yield 'an incomplete chapter' => [['chapters' => [['title' => 'x']]] + $valid];
        yield 'a chapter not an object' => [['chapters' => ['x']] + $valid];
        yield 'a chapter as a list' => [['chapters' => [[0, 'Intro']]] + $valid];
    }
}
