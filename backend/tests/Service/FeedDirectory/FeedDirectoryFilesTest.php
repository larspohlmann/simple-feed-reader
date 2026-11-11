<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use App\Service\FeedDirectory\FeedDirectoryFiles;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryFilesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/feed-directory';

    private function files(): FeedDirectoryFiles
    {
        return new FeedDirectoryFiles(self::FIXTURES);
    }

    public function testItListsTheIndexedCountryCodes(): void
    {
        self::assertSame(['AT', 'DE'], $this->files()->countryCodes());
    }

    public function testItListsTheIndexedLanguageCodes(): void
    {
        self::assertSame(['de', 'en'], $this->files()->languageCodes());
    }

    public function testItResolvesAKnownCountryToItsFile(): void
    {
        self::assertSame(self::FIXTURES . '/countries/DE.json.gz', $this->files()->countryPath('DE'));
    }

    public function testItPointsAtTheIndex(): void
    {
        self::assertSame(self::FIXTURES . '/index.json', $this->files()->indexPath());
    }

    public function testAnUnknownCountryIsRejected(): void
    {
        $this->expectException(UnknownFeedDirectoryCountryException::class);

        $this->files()->countryPath('FR');
    }

    public function testACodeCarryingPathCharactersIsRejected(): void
    {
        $this->expectException(UnknownFeedDirectoryCountryException::class);

        $this->files()->countryPath('../index');
    }

    public function testALowerCaseCodeIsRejected(): void
    {
        $this->expectException(UnknownFeedDirectoryCountryException::class);

        $this->files()->countryPath('de');
    }

    public function testAKnownScopePasses(): void
    {
        $this->files()->requireKnown(new FeedDirectoryScopeModel(['DE', 'AT'], ['de']));

        $this->addToAssertionCount(1);
    }

    public function testAnEmptyScopePasses(): void
    {
        $this->files()->requireKnown(new FeedDirectoryScopeModel([], []));

        $this->addToAssertionCount(1);
    }

    public function testAnUnknownCountryInTheScopeIsRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        $this->files()->requireKnown(new FeedDirectoryScopeModel(['DE', 'FR'], ['de']));
    }

    public function testAnUnknownLanguageInTheScopeIsRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        $this->files()->requireKnown(new FeedDirectoryScopeModel(['DE'], ['xx']));
    }
}
