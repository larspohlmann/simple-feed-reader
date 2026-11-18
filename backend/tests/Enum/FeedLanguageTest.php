<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\FeedLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedLanguageTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null}> */
    public static function tags(): iterable
    {
        yield 'bare German' => ['de', 'de'];
        yield 'regional German' => ['de-AT', 'de'];
        yield 'underscored English' => ['en_US', 'en'];
        yield 'padded and shouted' => [' EN ', 'en'];
        yield 'Canadian French' => ['fr-CA', 'fr'];
        yield 'Latin American Spanish' => ['es-419', 'es'];
        yield 'unsupported language' => ['it', null];
        yield 'empty' => ['', null];
        yield 'none' => [null, null];
        yield 'three-letter code' => ['deu', null];
    }

    public function testEachLanguageHasItsEnglishName(): void
    {
        self::assertSame(
            ['German', 'English', 'French', 'Spanish'],
            array_map(static fn (FeedLanguage $language): string => $language->englishName(), FeedLanguage::cases()),
        );
    }

    public function testValuesListsEveryLanguageCodeInCaseOrder(): void
    {
        self::assertSame(['de', 'en', 'fr', 'es'], FeedLanguage::values());
    }

    #[DataProvider('tags')]
    public function testATagNamesItsPrimaryLanguageWhenSupported(?string $tag, ?string $expected): void
    {
        self::assertSame($expected, FeedLanguage::fromTag($tag)?->value);
    }
}
