<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Llm\Completion\Support;

use App\Service\Ai\Llm\Completion\Support\CompletionFinishReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompletionFinishReasonTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function finishReasons(): iterable
    {
        yield 'an upstream error mid-stream' => ['error', true];
        yield 'a content filter' => ['content_filter', true];
        yield 'a natural end' => ['stop', false];
        yield 'the max_tokens ceiling' => ['length', false];
        yield 'no finish reason yet' => [null, false];
    }

    #[DataProvider('finishReasons')]
    public function testOnlyTheProviderEndingTheAnswerIsACut(?string $finishReason, bool $cut): void
    {
        self::assertSame($cut, CompletionFinishReason::cutByProvider($finishReason));
    }
}
