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
        $this->assertSame([], array_filter(self::$verbRows, static fn (array $row): bool => 2 !== count($row)));
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

    public function testMetaDescribesTheNouns(): void
    {
        $this->assertMetaDescribesTheNouns(self::DIRECTORY, self::$formRows);
    }

    public function testFixtureMetaFollowsTheSameDefinition(): void
    {
        $fixtureRows = self::rowsOf(self::FIXTURE_DIRECTORY, 'lexicon.tsv');

        $this->assertMetaDescribesTheNouns(self::FIXTURE_DIRECTORY, $fixtureRows);
    }

    /** @param list<list<string>> $formRows */
    private function assertMetaDescribesTheNouns(string $directory, array $formRows): void
    {
        $meta = require $directory . '/lexicon-meta.php';
        $this->assertIsArray($meta);
        $classes = array_map(intval(...), array_column($formRows, 2));
        $rarest = max($classes ?: [0]);

        $this->assertSame(self::classFloorsOf($formRows, $rarest), $meta['nounClassFloorByLength']);
        $this->assertSame($rarest, $meta['rarest']);
    }

    /**
     * The class at which the nouns of a length, each weighing 2^-class, reach 99 % of their total weight.
     *
     * @param list<list<string>> $formRows
     * @return array<int, int>
     */
    private static function classFloorsOf(array $formRows, int $rarest): array
    {
        $weightsByLength = [];
        foreach ($formRows as [$form, $kind, $class]) {
            if ('n' === $kind) {
                $weightsByLength[mb_strlen($form)][(int) $class] ??= 0;
                $weightsByLength[mb_strlen($form)][(int) $class] += 1 << ($rarest - (int) $class);
            }
        }
        ksort($weightsByLength);

        return array_map(static function (array $weightByClass): int {
            ksort($weightByClass);
            $total = array_sum($weightByClass);
            $cumulative = 0;
            foreach ($weightByClass as $class => $weight) {
                $cumulative += $weight;
                if (100 * $cumulative >= 99 * $total) {
                    return $class;
                }
            }

            return array_key_last($weightByClass);
        }, $weightsByLength);
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
