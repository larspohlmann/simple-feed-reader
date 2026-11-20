<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\Model\GermanLexiconEntryModel;
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

    public function findEntry(string $form): ?GermanLexiconEntryModel
    {
        return $this->model()->findEntry($form);
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
