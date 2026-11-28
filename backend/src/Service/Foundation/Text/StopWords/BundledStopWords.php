<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\StopWords;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\Support\CaseFold;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

final class BundledStopWords implements StopWordsInterface, ResetInterface
{
    private mixed $lists = null;

    /** @var array<string, array<string, true>> language => its stop words, casefolded */
    private array $wordSets = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/stopwords/stopwords.php')]
        private readonly string $path,
    ) {
    }

    public function listWords(FeedLanguage $language): array
    {
        $this->lists ??= require $this->path;
        $words = \is_array($this->lists) ? $this->lists[$language->value] ?? null : null;
        if (!\is_array($words) || !array_is_list($words)) {
            throw $this->buildMissingListException($language);
        }
        $checked = [];
        foreach ($words as $word) {
            $checked[] = \is_string($word) ? $word : throw $this->buildMissingListException($language);
        }

        return $checked;
    }

    public function isStopWord(string $casefoldedWord, FeedLanguage $language): bool
    {
        $this->wordSets[$language->value] ??= $this->buildWordSet($language);

        return isset($this->wordSets[$language->value][$casefoldedWord]);
    }

    public function reset(): void
    {
        $this->lists = null;
        $this->wordSets = [];
    }

    /** @return array<string, true> */
    private function buildWordSet(FeedLanguage $language): array
    {
        $words = array_map(
            static fn (string $word): string => CaseFold::normalized($word, \Normalizer::FORM_KC),
            $this->listWords($language),
        );

        return array_fill_keys($words, true);
    }

    private function buildMissingListException(FeedLanguage $language): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('%s holds no word list for %s', $this->path, $language->value));
    }
}
