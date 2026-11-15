<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StopWords;

use App\Enum\FeedLanguage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BundledStopWords implements StopWordsInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/snowball-stopwords/stopwords.php')]
        private string $path,
    ) {
    }

    public function wordsOf(FeedLanguage $language): array
    {
        $lists = require $this->path;
        $words = \is_array($lists) ? $lists[$language->value] ?? null : null;
        if (!\is_array($words) || !array_is_list($words)) {
            throw $this->noListFor($language);
        }
        $checked = [];
        foreach ($words as $word) {
            $checked[] = \is_string($word) ? $word : throw $this->noListFor($language);
        }

        return $checked;
    }

    private function noListFor(FeedLanguage $language): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('%s holds no word list for %s', $this->path, $language->value));
    }
}
