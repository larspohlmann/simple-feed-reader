<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Model;

use App\Service\Image\Model\ImageVerificationBudgetModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageVerificationBudgetModelTest extends TestCase
{
    private const string NOW = '2026-10-29 12:00:00';

    /** @return iterable<string, array{int, int, string}> */
    public static function windows(): iterable
    {
        yield 'half of what is left' => [41, 45, '2026-10-29 12:00:20'];
        yield 'capped in a long window' => [1000, 45, '2026-10-29 12:00:45'];
        yield 'nothing once the deadline has passed' => [-10, 45, self::NOW];
    }

    #[DataProvider('windows')]
    public function testHalfOfWhatIsLeft(int $secondsLeft, int $cap, string $expectedDeadline): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $deadline = $now->modify(\sprintf('%+d seconds', $secondsLeft));

        $budget = ImageVerificationBudgetModel::halfOfWhatIsLeft($deadline, $now, $cap);

        self::assertEquals(new \DateTimeImmutable($expectedDeadline), $budget->deadlineFrom($now));
    }

    public function testRejectsANegativeBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImageVerificationBudgetModel::seconds(-1);
    }
}
