<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Enum\YourStreamPrecision;
use App\Service\Curation\YourStream\Model\YourStreamSettingsStateModel;

/** Your stream's switch, precision and word list, grouped by language with the cut form last. */
final class YourStreamSettingsJson
{
    /**
     * @return array{
     *     enabled: bool,
     *     precision: string,
     *     defaultPrecision: string,
     *     words: list<array{language: ?string, words: list<array{word: string, weight: float}>}>,
     * }
     */
    public static function state(YourStreamSettingsStateModel $state): array
    {
        return [
            'enabled' => $state->tuning->isEnabled(),
            'precision' => $state->tuning->getPrecision()->value,
            'defaultPrecision' => YourStreamPrecision::DEFAULT->value,
            'words' => self::wordsByLanguage($state->words),
        ];
    }

    /**
     * @param list<YourStreamWord> $words
     *
     * @return list<array{language: ?string, words: list<array{word: string, weight: float}>}>
     */
    private static function wordsByLanguage(array $words): array
    {
        $weightsByLanguage = [];
        foreach ($words as $word) {
            $language = $word->getLanguage()->value ?? '';
            $highest = max($weightsByLanguage[$language][$word->getWord()] ?? 0.0, $word->getWeight());
            $weightsByLanguage[$language][$word->getWord()] = $highest;
        }
        $groups = [];
        foreach ([...FeedLanguage::cases(), null] as $language) {
            $weights = $weightsByLanguage[$language->value ?? ''] ?? [];
            if ([] !== $weights) {
                $groups[] = ['language' => $language?->value, 'words' => self::sortStrongestFirst($weights)];
            }
        }

        return $groups;
    }

    /**
     * @param array<array-key, float> $weights word => its highest weight
     *
     * @return list<array{word: string, weight: float}> strongest first, equal weights by word
     */
    private static function sortStrongestFirst(array $weights): array
    {
        $entries = [];
        foreach ($weights as $word => $weight) {
            $entries[] = ['word' => (string) $word, 'weight' => $weight];
        }
        usort($entries, static fn (array $left, array $right): int => [$right['weight'], $left['word']]
            <=> [$left['weight'], $right['word']]);

        return $entries;
    }
}
