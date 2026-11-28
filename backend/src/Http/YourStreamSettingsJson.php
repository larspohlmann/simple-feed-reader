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
        $groups = [];
        foreach ([...FeedLanguage::cases(), null] as $language) {
            $inLanguage = self::strongestPerWord(array_filter(
                $words,
                static fn (YourStreamWord $word): bool => $word->getLanguage() === $language,
            ));
            if ([] === $inLanguage) {
                continue;
            }
            $groups[] = ['language' => $language?->value, 'words' => $inLanguage];
        }

        return $groups;
    }

    /**
     * @param array<YourStreamWord> $words
     *
     * @return list<array{word: string, weight: float}> each word once at its highest weight, strongest first
     */
    private static function strongestPerWord(array $words): array
    {
        $weights = [];
        foreach ($words as $word) {
            $weights[$word->getWord()] = max($weights[$word->getWord()] ?? 0.0, $word->getWeight());
        }
        $entries = array_map(
            static fn (string $word, float $weight): array => ['word' => $word, 'weight' => $weight],
            array_map(strval(...), array_keys($weights)),
            array_values($weights),
        );
        usort($entries, static fn (array $left, array $right): int => [$right['weight'], $left['word']]
            <=> [$left['weight'], $right['word']]);

        return $entries;
    }
}
