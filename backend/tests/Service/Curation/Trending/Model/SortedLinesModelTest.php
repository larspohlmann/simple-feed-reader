<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\SortedLinesModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SortedLinesModelTest extends TestCase
{
    private const string LINES = "ab\t1\nabc\t2\nabd\t3\nb\t4\nzebra\t5\nächzen\t6\n";

    /** @return iterable<string, array{string, ?string}> */
    public static function lookups(): iterable
    {
        yield 'first line' => ['ab', '1'];
        yield 'prefix of the next key' => ['abc', '2'];
        yield 'middle line' => ['b', '4'];
        yield 'last line' => ['ächzen', '6'];
        yield 'before the first' => ['a', null];
        yield 'between two lines' => ['abe', null];
        yield 'after the last' => ['ö', null];
        yield 'empty key' => ['', null];
        yield 'a key that continues a line' => ['zebras', null];
        yield 'a key that is a prefix of a line' => ['zebr', null];
    }

    #[DataProvider('lookups')]
    public function testFindsTheValueOfAKey(string $key, ?string $expected): void
    {
        $this->assertSame($expected, (new SortedLinesModel(self::LINES))->findValue($key));
    }

    public function testFindsEveryLineOfALargerText(): void
    {
        $lines = '';
        for ($number = 0; $number < 500; ++$number) {
            $lines .= sprintf("key%04d\t%d\n", $number * 2, $number);
        }
        $model = new SortedLinesModel($lines);

        for ($number = 0; $number < 500; ++$number) {
            $this->assertSame((string) $number, $model->findValue(sprintf('key%04d', $number * 2)));
            $this->assertNull($model->findValue(sprintf('key%04d', $number * 2 + 1)));
        }
    }

    public function testFindsNothingInAnEmptyText(): void
    {
        $this->assertNull((new SortedLinesModel(''))->findValue('ab'));
    }
}
