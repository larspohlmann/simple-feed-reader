<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory\Model;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryScopeModelTest extends TestCase
{
    public function testBothListsEmptyIsAClearedScope(): void
    {
        self::assertTrue(new FeedDirectoryScopeModel([], [])->isEmpty());
    }

    public function testBothListsFilledIsAScope(): void
    {
        self::assertFalse(new FeedDirectoryScopeModel(['DE'], ['de'])->isEmpty());
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
