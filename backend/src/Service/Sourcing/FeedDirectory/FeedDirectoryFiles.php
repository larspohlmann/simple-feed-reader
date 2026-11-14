<?php

declare(strict_types=1);

namespace App\Service\Sourcing\FeedDirectory;

use App\Service\Sourcing\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\Sourcing\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use App\Service\Sourcing\FeedDirectory\Model\FeedDirectoryScopeModel;
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
        return self::countryCodesIn($this->index());
    }

    /** @return list<string> */
    public function languageCodes(): array
    {
        return self::languageCodesIn($this->index());
    }

    public function requireKnown(FeedDirectoryScopeModel $scope): void
    {
        $index = $this->index();
        $unknownCountries = array_diff($scope->countries, self::countryCodesIn($index));
        $unknownLanguages = array_diff($scope->languages, self::languageCodesIn($index));
        if ($unknownCountries !== [] || $unknownLanguages !== []) {
            throw new InvalidFeedDirectoryScopeException(
                \sprintf(
                    'Unknown in the feed directory: %s.',
                    implode(', ', [...$unknownCountries, ...$unknownLanguages]),
                ),
            );
        }
    }

    /**
     * @param array<mixed> $index
     *
     * @return list<string>
     */
    private static function countryCodesIn(array $index): array
    {
        $codes = [];
        foreach (self::entriesAt($index, 'countries') as $country) {
            if (\is_array($country) && \is_string($country['code'] ?? null)) {
                $codes[] = $country['code'];
            }
        }

        return $codes;
    }

    /**
     * @param array<mixed> $index
     *
     * @return list<string>
     */
    private static function languageCodesIn(array $index): array
    {
        return array_values(array_filter(array_keys(self::entriesAt($index, 'languages')), is_string(...)));
    }

    /**
     * @param array<mixed> $index
     *
     * @return array<mixed>
     */
    private static function entriesAt(array $index, string $key): array
    {
        $value = $index[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    /** @return array<mixed> */
    private function index(): array
    {
        $decoded = json_decode((string) file_get_contents($this->indexPath()), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
