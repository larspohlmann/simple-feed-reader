<?php

declare(strict_types=1);

namespace App\Tests\Service\Category;

use App\Service\Category\CategoryNormalizer;
use App\Service\Parser\ParsedCategory;
use PHPUnit\Framework\TestCase;

final class CategoryNormalizerTest extends TestCase
{
    private CategoryNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CategoryNormalizer();
    }

    public function testTrimsAndDropsEmpty(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory('  Politics  '),
            new ParsedCategory('   '),
            new ParsedCategory(''),
        ]);

        self::assertCount(1, $out);
        self::assertSame('Politics', $out[0]->displayLabel);
        self::assertSame('politics', $out[0]->canonicalKey);
        self::assertSame('', $out[0]->scheme);
    }

    public function testCanonicalKeyLowercasesAndCollapsesWhitespace(): void
    {
        $out = $this->normalizer->normalize([new ParsedCategory("Middle   East\tNews")]);

        self::assertSame('middle east news', $out[0]->canonicalKey);
        self::assertSame("Middle   East\tNews", $out[0]->displayLabel);
    }

    public function testDeduplicatesCaseInsensitivelyKeepingFirstLabel(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory('Politics'),
            new ParsedCategory('POLITICS'),
        ]);

        self::assertCount(1, $out);
        self::assertSame('Politics', $out[0]->displayLabel);
    }

    public function testSameLabelDifferentSchemeStaySeparate(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory('Politics', 'https://a.test'),
            new ParsedCategory('Politics', 'https://b.test'),
        ]);

        self::assertCount(2, $out);
    }

    public function testCapsCountAtThirty(): void
    {
        $raw = [];
        for ($i = 0; $i < 40; $i++) {
            $raw[] = new ParsedCategory('cat' . $i);
        }

        self::assertCount(30, $this->normalizer->normalize($raw));
    }

    public function testTruncatesLongValues(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory(str_repeat('a', 200), str_repeat('s', 400)),
        ]);

        self::assertSame(128, mb_strlen($out[0]->displayLabel));
        self::assertSame(128, mb_strlen($out[0]->canonicalKey));
        self::assertSame(255, mb_strlen($out[0]->scheme));
    }
}
