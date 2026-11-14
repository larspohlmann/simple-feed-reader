<?php

declare(strict_types=1);

namespace App\Tests\Service\Sourcing\FeedDirectory\Model;

use App\Service\Sourcing\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\Sourcing\FeedDirectory\Model\FeedDirectoryScopeModel;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryScopeModelTest extends TestCase
{
    public function testAHalfScopeIsNoScope(): void
    {
        $withoutLanguages = FeedDirectoryScopeModel::orNone(['DE'], []);
        $withoutCountries = FeedDirectoryScopeModel::orNone([], ['de']);

        self::assertSame([[], []], [$withoutLanguages->countries, $withoutLanguages->languages]);
        self::assertSame([[], []], [$withoutCountries->countries, $withoutCountries->languages]);
    }

    public function testAFullScopeIsKept(): void
    {
        $scope = FeedDirectoryScopeModel::orNone(['DE', 'DE'], ['de']);

        self::assertSame([['DE'], ['de']], [$scope->countries, $scope->languages]);
    }

    public function testCountriesWithoutLanguagesAreRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        new FeedDirectoryScopeModel(['DE'], []);
    }

    public function testLanguagesWithoutCountriesAreRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        new FeedDirectoryScopeModel([], ['de']);
    }

    public function testRepeatedValuesCollapse(): void
    {
        $scope = new FeedDirectoryScopeModel(['DE', 'DE', 'AT'], ['de', 'de']);

        self::assertSame(['DE', 'AT'], $scope->countries);
        self::assertSame(['de'], $scope->languages);
    }

    public function testRepeatedLanguagesCollapseIntoASequentialList(): void
    {
        $scope = new FeedDirectoryScopeModel(['DE', 'AT', 'CH'], ['de', 'de', 'en']);

        self::assertSame(['de', 'en'], $scope->languages);
        self::assertSame(['DE', 'AT', 'CH'], $scope->countries);
    }
}
