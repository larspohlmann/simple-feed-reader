<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\EntryView;
use App\Exception\ValidationException;
use App\Repository\LabelScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabelScopeTest extends TestCase
{
    public function testNoLabelIsNoScope(): void
    {
        self::assertNull(LabelScope::fromRequestValue(null, EntryView::Favorites));
    }

    public function testAnIdOnFavoritesScopesThatLabel(): void
    {
        $scope = LabelScope::fromRequestValue('12', EntryView::Favorites);

        self::assertEquals(LabelScope::forLabel(12), $scope);
        self::assertSame(12, $scope?->labelId);
    }

    public function testUnlabeledOnKeptScopesTheUnlabeledEntries(): void
    {
        $scope = LabelScope::fromRequestValue('unlabeled', EntryView::Kept);

        self::assertEquals(LabelScope::unlabeled(), $scope);
        self::assertNull($scope?->labelId);
    }

    public function testALabelOutsideFavoritesAndKeptIsAValidationError(): void
    {
        $this->assertRefused(
            ['label' => ['A label needs view=favorites or view=kept.']],
            static fn () => LabelScope::fromRequestValue('12', EntryView::All),
        );
    }

    #[DataProvider('provideMalformedLabels')]
    public function testAValueThatIsNeitherAnIdNorUnlabeledIsAValidationError(string $label): void
    {
        $this->assertRefused(
            ['label' => ['A label is an id or "unlabeled".']],
            static fn () => LabelScope::fromRequestValue($label, EntryView::Kept),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideMalformedLabels(): iterable
    {
        yield 'zero' => ['0'];
        yield 'word' => ['abc'];
        yield 'negative' => ['-3'];
    }

    /** @param array<string, list<string>> $expected */
    private function assertRefused(array $expected, callable $parse): void
    {
        try {
            $parse();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame($expected, $exception->errors);
        }
    }
}
