<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FeedDirectoryFiles
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/feed-directory')]
        private string $directory,
    ) {
    }

    public function indexPath(): string
    {
        return $this->directory . '/index.json';
    }

    public function countryPath(string $countryCode): string
    {
        if (!\in_array($countryCode, $this->countryCodes(), true)) {
            throw new UnknownFeedDirectoryCountryException(
                \sprintf('No feed directory for country "%s".', $countryCode),
            );
        }

        return \sprintf('%s/countries/%s.json.gz', $this->directory, $countryCode);
    }

    /** @return list<string> */
    public function countryCodes(): array
    {
        $codes = [];
        foreach ($this->entriesAt('countries') as $country) {
            if (\is_array($country) && \is_string($country['code'] ?? null)) {
                $codes[] = $country['code'];
            }
        }

        return $codes;
    }

    /** @return list<string> */
    public function languageCodes(): array
    {
        return array_values(array_filter(array_keys($this->entriesAt('languages')), is_string(...)));
    }

    public function requireKnown(FeedDirectoryScopeModel $scope): void
    {
        $unknownCountries = array_diff($scope->countries, $this->countryCodes());
        $unknownLanguages = array_diff($scope->languages, $this->languageCodes());
        if ($unknownCountries !== [] || $unknownLanguages !== []) {
            throw new InvalidFeedDirectoryScopeException(
                \sprintf(
                    'Unknown in the feed directory: %s.',
                    implode(', ', [...$unknownCountries, ...$unknownLanguages]),
                ),
            );
        }
    }

    /** @return array<mixed> */
    private function entriesAt(string $key): array
    {
        $value = $this->index()[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    /** @return array<mixed> */
    private function index(): array
    {
        $decoded = json_decode((string) file_get_contents($this->indexPath()), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
