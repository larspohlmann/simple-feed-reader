<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\GermanLexicon;

use PHPUnit\Framework\TestCase;

final class BundledLexiconDataTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../../../../resources/german-lexicon';
    private const string FIXTURE_DIRECTORY = __DIR__ . '/../../../../Fixtures/german-lexicon';

    /** @var list<list<string>> */
    private static array $formRows;

    /** @var list<list<string>> */
    private static array $verbRows;

    public static function setUpBeforeClass(): void
    {
        self::$formRows = self::rowsOf(self::DIRECTORY, 'lexicon.tsv');
        self::$verbRows = self::rowsOf(self::DIRECTORY, 'verbs.tsv');
    }

    public function testFilesStayUnderSixMebibytesTogether(): void
    {
        $bytes = array_sum(array_map(
            static fn (string $name): int => (int) filesize(self::DIRECTORY . '/' . $name),
            ['lexicon.tsv', 'verbs.tsv', 'lexicon-meta.php'],
        ));

        $this->assertLessThanOrEqual(6_291_456, $bytes);
    }

    public function testEveryLineIsATerminatedKeyedRow(): void
    {
        $this->assertStringEndsWith("\n", (string) file_get_contents(self::DIRECTORY . '/lexicon.tsv'));
        $this->assertStringEndsWith("\n", (string) file_get_contents(self::DIRECTORY . '/verbs.tsv'));
        $this->assertSame([], array_filter(self::$formRows, static fn (array $row): bool => 3 !== count($row)));
        $this->assertSame([], array_filter(self::$verbRows, static fn (array $row): bool => 3 !== count($row)));
    }

    public function testEveryFormIsAtLeastThreeCharactersLong(): void
    {
        $keys = array_column([...self::$formRows, ...self::$verbRows], 0);

        $this->assertSame([], array_filter($keys, static fn (string $key): bool => mb_strlen($key) < 3));
    }

    public function testKeysAreByteSortedWithoutDuplicates(): void
    {
        foreach ([self::$formRows, self::$verbRows] as $rows) {
            $keys = array_column($rows, 0);
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            $this->assertSame($sorted, $keys);
            $this->assertSame($keys, array_values(array_unique($keys)));
        }
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

    public function testEveryVerbMapsToANonEmptyInfinitiveStem(): void
    {
        $this->assertSame([], array_filter(self::$verbRows, static fn (array $row): bool => '' === $row[1]));
    }

    public function testEveryVerbHasANonNegativeInfinitiveClass(): void
    {
        $invalid = array_filter(
            [...self::$verbRows, ...self::rowsOf(self::FIXTURE_DIRECTORY, 'verbs.tsv')],
            static fn (array $row): bool => 1 !== preg_match('/^(0|[1-9]\d*)$/', $row[2]),
        );

        $this->assertSame([], $invalid);
    }

    public function testMetaHoldsTheRarestClass(): void
    {
        $this->assertMetaHoldsTheRarestClass(self::DIRECTORY, self::$formRows);
    }

    public function testFixtureMetaHoldsTheRarestClass(): void
    {
        $fixtureRows = self::rowsOf(self::FIXTURE_DIRECTORY, 'lexicon.tsv');

        $this->assertMetaHoldsTheRarestClass(self::FIXTURE_DIRECTORY, $fixtureRows);
    }

    /** @param list<list<string>> $formRows */
    private function assertMetaHoldsTheRarestClass(string $directory, array $formRows): void
    {
        $meta = require $directory . '/lexicon-meta.php';
        $this->assertIsArray($meta);
        $classes = array_map(intval(...), array_column($formRows, 2));
        $rarest = max($classes ?: [0]);

        $this->assertSame(['rarest' => $rarest], $meta);
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
