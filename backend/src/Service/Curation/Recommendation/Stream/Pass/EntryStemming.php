<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Stream\Model\EntryStemsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\StreamEntryModel;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\WordEquivalents;
use App\Service\Foundation\Text\Support\EntryPlainText;

final class EntryStemming
{
    public const float HEADLINE_WEIGHT = 1.0;
    public const float BODY_WEIGHT = 0.5;
    public const int BODY_CHARACTERS = 600;

    /** @var array<string, list<string>> the stems of a word, by language value and word */
    private array $stemsByWord = [];

    public function __construct(
        private readonly WordEquivalents $equivalents,
        private readonly StopWordStems $stopWordStems,
    ) {
    }

    public function collectStems(StreamEntryModel $entry): EntryStemsModel
    {
        $bodyText = EntryPlainText::cut($entry->summary, self::BODY_CHARACTERS);
        $weights = [];
        foreach ($this->readStems($bodyText, $entry->language) as $stem) {
            $weights[$stem] = self::BODY_WEIGHT;
        }
        foreach ($this->readStems($entry->title, $entry->language) as $stem) {
            $weights[$stem] = self::HEADLINE_WEIGHT;
        }

        return new EntryStemsModel($entry->language, $weights);
    }

    /** @return list<string> */
    private function readStems(string $text, ?FeedLanguage $language): array
    {
        $stems = [];
        foreach ($this->equivalents->canonicalizeWords($text) as [$word, $bare]) {
            array_push($stems, ...$this->findWordStems($word, $bare, $language));
        }

        return $stems;
    }

    /** @return list<string> */
    private function findWordStems(string $word, string $bare, ?FeedLanguage $language): array
    {
        $key = InterestTermsModel::languageKey($language) . ':' . $word;

        return $this->stemsByWord[$key] ??= array_values(array_filter(
            $this->equivalents->stemWordAndParts($word, $bare, $language),
            fn (string $stem): bool => !$this->stopWordStems->isStopWordStem($stem, $language),
        ));
    }
}
