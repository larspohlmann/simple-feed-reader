<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\StopWords\StopWordsInterface;
use App\Service\Foundation\Text\Support\CasefoldedWords;
use App\Service\Foundation\Text\Support\LinkFreeText;

/** The language whose bundled stopwords a short text holds most, outright (#2243). */
final readonly class StopWordVote
{
    /** A single letter is a contraction's tail or an initial, and the French list holds nine. */
    private const int MIN_WORD_LENGTH = 2;

    /** A link without its scheme, a mention or a hashtag is no running text in the post's language. */
    private const string BARE_LINK_MENTION_OR_HASHTAG = '~(?<![^\s/])[^\s/]+\.\p{L}{2,}/\S*|[@#]\S+~u';

    /** An acronym is no function word: English "AI" is not French "ai". */
    private const string WORD_IN_CAPITALS = '/(?<![\p{L}\p{M}\p{N}])\p{Lu}[\p{Lu}\p{M}]+(?![\p{L}\p{M}\p{N}])/u';

    /** @var array<string, array<string, true>> */
    private array $stopWordsByLanguage;

    public function __construct(StopWordsInterface $stopWords)
    {
        $sets = [];
        foreach (FeedLanguage::cases() as $language) {
            $sets[$language->value] = array_fill_keys($stopWords->listWords($language), true);
        }
        $this->stopWordsByLanguage = $sets;
    }

    public function languageOf(string $text): ?FeedLanguage
    {
        $counts = $this->stopWordCounts($this->votingWords($text));
        arsort($counts);
        [$highest, $runnerUp] = array_values($counts);
        if ($highest === $runnerUp) {
            return null;
        }

        return FeedLanguage::from((string) array_key_first($counts));
    }

    /** @return array<int, string> */
    private function votingWords(string $text): array
    {
        $notRunningText = [self::BARE_LINK_MENTION_OR_HASHTAG, self::WORD_IN_CAPITALS];
        $runningText = preg_replace($notRunningText, ' ', LinkFreeText::stripLinks($text)) ?? $text;
        $words = CasefoldedWords::splitWords($runningText);

        return array_filter($words, static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH);
    }

    /**
     * @param array<int, string> $words
     *
     * @return array<string, int>
     */
    private function stopWordCounts(array $words): array
    {
        return array_map(
            static fn (array $stopWords): int => \count(array_filter(
                $words,
                static fn (string $word): bool => isset($stopWords[$word]),
            )),
            $this->stopWordsByLanguage,
        );
    }
}
