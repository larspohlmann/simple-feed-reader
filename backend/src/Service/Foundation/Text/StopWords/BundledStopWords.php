<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\StopWords;

use App\Enum\FeedLanguage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

final class BundledStopWords implements StopWordsInterface, ResetInterface
{
    private mixed $lists = null;

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
            throw $this->noListFor($language);
        }
        $checked = [];
        foreach ($words as $word) {
            $checked[] = \is_string($word) ? $word : throw $this->noListFor($language);
        }

        return $checked;
    }

    public function reset(): void
    {
        $this->lists = null;
    }

    private function noListFor(FeedLanguage $language): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('%s holds no word list for %s', $this->path, $language->value));
    }
}
