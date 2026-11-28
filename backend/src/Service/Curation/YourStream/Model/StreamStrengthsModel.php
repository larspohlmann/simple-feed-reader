<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\FeedLanguage;

final readonly class StreamStrengthsModel
{
    /** @param array<string, array<array-key, float>> $strengthsByLanguageKey weight × rarity per stem */
    private function __construct(private array $strengthsByLanguageKey)
    {
    }

    /** @param array<string, array<array-key, float>> $strengthsByLanguageKey */
    public static function fromStrengths(array $strengthsByLanguageKey): self
    {
        return new self($strengthsByLanguageKey);
    }

    public static function languageKey(?FeedLanguage $language): string
    {
        return $language->value ?? '';
    }

    /** @return array<array-key, float> weight × rarity per stem, empty when the language has no terms */
    public function readStrengths(?FeedLanguage $language): array
    {
        return $this->strengthsByLanguageKey[self::languageKey($language)] ?? [];
    }
}
