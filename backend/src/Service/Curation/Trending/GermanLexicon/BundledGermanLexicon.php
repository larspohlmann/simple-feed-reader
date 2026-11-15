<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\Model\GermanLexiconModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

final class BundledGermanLexicon implements GermanLexiconInterface, ResetInterface
{
    private ?GermanLexiconModel $model = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/german-lexicon')]
        private readonly string $directory,
    ) {
    }

    public function isNoun(string $form): bool
    {
        return $this->model()->isNoun($form);
    }

    public function isKnown(string $form): bool
    {
        return $this->model()->isKnown($form);
    }

    public function frequencyClassOf(string $form): int
    {
        return $this->model()->frequencyClassOf($form);
    }

    public function medianNounClassOfLength(int $length): int
    {
        return $this->model()->medianNounClassOfLength($length);
    }

    public function isVerbStem(string $presentStem): bool
    {
        return $this->model()->isVerbStem($presentStem);
    }

    public function infinitiveStemOf(string $presentStem): string
    {
        return $this->model()->infinitiveStemOf($presentStem);
    }

    public function reset(): void
    {
        $this->model = null;
    }

    private function model(): GermanLexiconModel
    {
        return $this->model ??= GermanLexiconModel::fromDirectory($this->directory);
    }
}
