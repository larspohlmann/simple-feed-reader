<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\GermanLexicon;

use PHPUnit\Framework\TestCase;

final class BundledLexiconDataTest extends TestCase
{
    private const string PATH = __DIR__ . '/../../../../../resources/german-lexicon/lexicon.php';

    private static mixed $lexicon;

    public static function setUpBeforeClass(): void
    {
        self::$lexicon = require self::PATH;
    }

    /** @return array<array-key, mixed> */
    private static function section(string $name): array
    {
        $lexicon = self::$lexicon;
        self::assertIsArray($lexicon);
        $section = $lexicon[$name];
        self::assertIsArray($section);

        return $section;
    }

    public function testFileStaysUnderSixMebibytes(): void
    {
        $this->assertLessThanOrEqual(6_291_456, filesize(self::PATH));
    }

    public function testEveryFormIsAtLeastThreeCharactersLong(): void
    {
        $forms = array_keys(self::section('nouns') + self::section('others') + self::section('verbs'));
        $tooShort = array_filter($forms, static fn (int|string $form): bool => mb_strlen((string) $form) < 3);

        $this->assertSame([], $tooShort);
    }

    public function testEveryClassIsANonNegativeInteger(): void
    {
        $classes = self::section('nouns') + self::section('others');
        $invalid = array_filter($classes, static fn (mixed $class): bool => !is_int($class) || $class < 0);

        $this->assertSame([], $invalid);
    }

    public function testNounsAndOthersDoNotOverlap(): void
    {
        $this->assertSame([], array_intersect_key(self::section('nouns'), self::section('others')));
    }

    public function testEveryVerbMapsToANonEmptyInfinitiveStem(): void
    {
        $invalid = array_filter(
            self::section('verbs'),
            static fn (mixed $stem): bool => !is_string($stem) || '' === $stem,
        );

        $this->assertSame([], $invalid);
    }
}
