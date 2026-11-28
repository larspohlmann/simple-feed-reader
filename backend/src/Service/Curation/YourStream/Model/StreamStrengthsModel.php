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

    /** @param list<StreamWordModel> $words */
    public static function fromWords(array $words): self
    {
        $strengths = [];
        foreach ($words as $word) {
            $strengths[self::languageKey($word->language)][$word->stem] = $word->strength();
        }

        return new self($strengths);
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
