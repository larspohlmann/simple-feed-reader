<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Stemming\GermanLexicon;

use PHPUnit\Framework\TestCase;

final class BundledLexiconDataTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../../../../resources/german-lexicon';

    /** @var list<list<string>> */
    private static array $formRows;

    public static function setUpBeforeClass(): void
    {
        self::$formRows = self::rowsOf(self::DIRECTORY, 'lexicon.tsv');
    }

    public function testTheFileStaysUnderSixMebibytes(): void
    {
        $this->assertLessThanOrEqual(6_291_456, (int) filesize(self::DIRECTORY . '/lexicon.tsv'));
    }

    public function testEveryLineIsATerminatedKeyedRow(): void
    {
        $this->assertStringEndsWith("\n", (string) file_get_contents(self::DIRECTORY . '/lexicon.tsv'));
        $this->assertSame([], array_filter(self::$formRows, static fn (array $row): bool => 3 !== count($row)));
    }

    public function testEveryFormIsAtLeastThreeCharactersLong(): void
    {
        $keys = array_column(self::$formRows, 0);

        $this->assertSame([], array_filter($keys, static fn (string $key): bool => mb_strlen($key) < 3));
    }

    public function testKeysAreByteSortedWithoutDuplicates(): void
    {
        $keys = array_column(self::$formRows, 0);
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function testEveryKindIsNounOrOther(): void
    {
        $invalid = array_filter(self::$formRows, static fn (array $row): bool => !in_array($row[1], ['n', 'o'], true));

        $this->assertSame([], $invalid);
    }

    public function testEveryClassIsANonNegativeInteger(): void
    {
        $invalid = array_filter(
            self::$formRows,
            static fn (array $row): bool => 1 !== preg_match('/^(0|[1-9]\d*)$/', $row[2]),
        );

        $this->assertSame([], $invalid);
    }

    /** @return list<list<string>> */
    private static function rowsOf(string $directory, string $name): array
    {
        return array_map(
            static fn (string $line): array => explode("\t", $line),
            explode("\n", rtrim((string) file_get_contents($directory . '/' . $name), "\n")),
        );
    }
}
