<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\GermanLexicon;

use PHPUnit\Framework\TestCase;

final class BundledLexiconDataTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../../../../resources/german-lexicon';

    /** @var list<list<string>> */
    private static array $formRows;

    /** @var list<list<string>> */
    private static array $verbRows;

    public static function setUpBeforeClass(): void
    {
        self::$formRows = self::rowsOf('lexicon.tsv');
        self::$verbRows = self::rowsOf('verbs.tsv');
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
        $meta = require self::DIRECTORY . '/lexicon-meta.php';
        $this->assertIsArray($meta);
        $classesByLength = [];
        foreach (self::$formRows as [$form, $kind, $class]) {
            if ('n' === $kind) {
                $classesByLength[mb_strlen($form)][] = (int) $class;
            }
        }
        ksort($classesByLength);
        $medians = array_map(static function (array $classes): int {
            sort($classes);

            return $classes[intdiv(count($classes), 2)];
        }, $classesByLength);

        $classes = array_map(intval(...), array_column(self::$formRows, 2));

        $this->assertSame($medians, $meta['medianNounClassByLength']);
        $this->assertSame(max($classes ?: [0]), $meta['rarest']);
    }

    /** @return list<list<string>> */
    private static function rowsOf(string $name): array
    {
        return array_map(
            static fn (string $line): array => explode("\t", $line),
            explode("\n", rtrim((string) file_get_contents(self::DIRECTORY . '/' . $name), "\n")),
        );
    }
}
