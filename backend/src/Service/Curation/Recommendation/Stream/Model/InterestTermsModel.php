<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

use App\Enum\FeedLanguage;

final readonly class InterestTermsModel
{
    /** @param array<string, array<string, float>> $strengthsByLanguageKey weight × rarity per stem */
    private function __construct(private array $strengthsByLanguageKey)
    {
    }

    /** @param array<string, array<string, float>> $strengthsByLanguageKey */
    public static function fromStrengths(array $strengthsByLanguageKey): self
    {
        return new self($strengthsByLanguageKey);
    }

    public static function languageKey(?FeedLanguage $language): string
    {
        return $language->value ?? '';
    }

    public function findStrength(?FeedLanguage $language, string $stem): ?float
    {
        return $this->strengthsByLanguageKey[self::languageKey($language)][$stem] ?? null;
    }

    public function isEmpty(): bool
    {
        return array_all($this->strengthsByLanguageKey, static fn (array $strengths): bool => $strengths === []);
    }
}
