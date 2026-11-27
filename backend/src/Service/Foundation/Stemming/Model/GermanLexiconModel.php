<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\Model;

final readonly class GermanLexiconModel
{
    private SortedLinesModel $forms;

    public function __construct(string $formLines)
    {
        $this->forms = new SortedLinesModel($formLines);
    }

    public static function fromDirectory(string $directory): self
    {
        return new self(self::readFile($directory . '/lexicon.tsv'));
    }

    public function findEntry(string $form): ?GermanLexiconEntryModel
    {
        $line = $this->forms->findValue($form);

        return null === $line ? null : new GermanLexiconEntryModel($line[0], (int) substr($line, 2));
    }

    private static function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \UnexpectedValueException(sprintf('The German lexicon file "%s" cannot be read.', $path));
        }

        return $contents;
    }
}
