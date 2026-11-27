<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\InterestPhrase;
use App\Enum\FeedLanguage;
use App\Enum\ForYouStreamPrecision;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamStateModel;

/** The For you stream's switch, precision and the AI's phrases, grouped by language for the settings card. */
final class ForYouStreamSettingsJson
{
    /**
     * @return array{
     *     enabled: bool,
     *     precision: string,
     *     defaultPrecision: string,
     *     phrases: list<array{language: string, phrases: list<array{phrase: string, weight: float}>}>,
     * }
     */
    public static function state(ForYouStreamStateModel $state): array
    {
        return [
            'enabled' => $state->tuning->isEnabled(),
            'precision' => $state->tuning->getPrecision()->value,
            'defaultPrecision' => ForYouStreamPrecision::DEFAULT->value,
            'phrases' => self::phrasesByLanguage($state->phrases),
        ];
    }

    /**
     * @param list<InterestPhrase> $phrases
     *
     * @return list<array{language: string, phrases: list<array{phrase: string, weight: float}>}>
     */
    private static function phrasesByLanguage(array $phrases): array
    {
        $groups = [];
        foreach (FeedLanguage::cases() as $language) {
            $inLanguage = array_values(array_filter(
                $phrases,
                static fn (InterestPhrase $phrase): bool => $phrase->language === $language,
            ));
            if ([] === $inLanguage) {
                continue;
            }
            usort($inLanguage, static fn (InterestPhrase $left, InterestPhrase $right): int => [
                $right->weight,
                $left->phrase,
            ] <=> [$left->weight, $right->phrase]);
            $groups[] = [
                'language' => $language->value,
                'phrases' => array_map(self::phraseEntry(...), $inLanguage),
            ];
        }

        return $groups;
    }

    /** @return array{phrase: string, weight: float} */
    private static function phraseEntry(InterestPhrase $phrase): array
    {
        return ['phrase' => $phrase->phrase, 'weight' => $phrase->weight];
    }
}
