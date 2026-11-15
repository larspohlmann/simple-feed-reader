<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class GermanLexiconModel
{
    private const string NOUN_KIND = 'n';

    private SortedLinesModel $forms;

    public function __construct(string $formLines, private int $rarestClass)
    {
        $this->forms = new SortedLinesModel($formLines);
    }

    public static function fromDirectory(string $directory): self
    {
        $meta = require $directory . '/lexicon-meta.php';
        if (!is_array($meta) || !is_int($meta['rarest'] ?? null)) {
            throw new \UnexpectedValueException('The German lexicon meta file has the wrong shape.');
        }

        return new self(self::contentsOf($directory . '/lexicon.tsv'), $meta['rarest']);
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

    private static function contentsOf(string $path): string
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \UnexpectedValueException(sprintf('The German lexicon file "%s" cannot be read.', $path));
        }

        return $contents;
    }
}
