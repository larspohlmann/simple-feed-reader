<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Exception\UnknownVerbStemException;

final readonly class GermanLexiconModel
{
    private const string NOUN_KIND = 'n';

    private SortedLinesModel $forms;

    private SortedLinesModel $verbs;

    public function __construct(string $formLines, string $verbLines, private int $rarestClass)
    {
        $this->forms = new SortedLinesModel($formLines);
        $this->verbs = new SortedLinesModel($verbLines);
    }

    public static function fromDirectory(string $directory): self
    {
        $meta = require $directory . '/lexicon-meta.php';
        if (!is_array($meta) || !is_int($meta['rarest'] ?? null)) {
            throw new \UnexpectedValueException('The German lexicon meta file has the wrong shape.');
        }

        return new self(
            self::contentsOf($directory . '/lexicon.tsv'),
            self::contentsOf($directory . '/verbs.tsv'),
            $meta['rarest'],
        );
    }

    public function isNoun(string $form): bool
    {
        return str_starts_with($this->forms->valueOf($form) ?? '', self::NOUN_KIND);
    }

    public function isKnown(string $form): bool
    {
        return null !== $this->forms->valueOf($form);
    }

    public function frequencyClassOf(string $form): int
    {
        $line = $this->forms->valueOf($form);

        return null === $line ? $this->rarestClass + 1 : (int) substr($line, 2);
    }

    public function isVerbStem(string $presentStem): bool
    {
        return null !== $this->verbs->valueOf($presentStem);
    }

    public function infinitiveStemOf(string $presentStem): string
    {
        return $this->verbRowOf($presentStem)[0];
    }

    public function infinitiveFrequencyClassOf(string $presentStem): int
    {
        return (int) $this->verbRowOf($presentStem)[1];
    }

    /** @return array{string, string} infinitive stem, infinitive class */
    private function verbRowOf(string $presentStem): array
    {
        $row = $this->verbs->valueOf($presentStem) ?? throw UnknownVerbStemException::forStem($presentStem);
        [$infinitiveStem, $class] = explode("\t", $row, 2);

        return [$infinitiveStem, $class];
    }

    private static function contentsOf(string $path): string
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \UnexpectedValueException(sprintf('The German lexicon file "%s" cannot be read.', $path));
        }

        return $contents;
    }
}
