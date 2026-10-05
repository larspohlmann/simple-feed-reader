<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Scoring\Support\QuestionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuestionIdTest extends TestCase
{
    public function testAnEntrysQuestionIdNamesThatEntry(): void
    {
        self::assertSame('entry-731', QuestionId::of(731));
        self::assertSame(731, QuestionId::entryIdOf('entry-731'));
    }

    /** @return iterable<string, array{string}> */
    public static function idsNoQuestionCarries(): iterable
    {
        yield 'a padded number' => ['entry-0731'];
        yield 'another prefix' => ['item-731'];
        yield 'no number' => ['entry-'];
        yield 'a trailing letter' => ['entry-731a'];
    }

    #[DataProvider('idsNoQuestionCarries')]
    public function testAnIdNoQuestionCarriesNamesNoEntry(string $questionId): void
    {
        self::assertNull(QuestionId::entryIdOf($questionId));
    }
}
