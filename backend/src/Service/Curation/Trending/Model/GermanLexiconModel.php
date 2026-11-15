<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Exception\UnknownVerbStemException;

final readonly class GermanLexiconModel
{
    private const string NOUN_KIND = 'n';

    private SortedLinesModel $forms;

    private SortedLinesModel $verbs;

    /** @param array<int, int> $nounClassFloorByLength */
    public function __construct(
        string $formLines,
        string $verbLines,
        private int $rarestClass,
        private array $nounClassFloorByLength,
    ) {
        $this->forms = new SortedLinesModel($formLines);
        $this->verbs = new SortedLinesModel($verbLines);
    }

    public static function fromDirectory(string $directory): self
    {
        $meta = require $directory . '/lexicon-meta.php';
        if (
            !is_array($meta)
            || !is_int($meta['rarest'] ?? null)
            || !is_array($meta['nounClassFloorByLength'] ?? null)
        ) {
            throw new \UnexpectedValueException('The German lexicon meta file has the wrong shape.');
        }
        $floors = array_filter($meta['nounClassFloorByLength'], is_int(...));
        if (count($floors) !== count($meta['nounClassFloorByLength'])) {
            throw new \UnexpectedValueException('The German lexicon meta file has a non-integer class floor.');
        }

        return new self(
            self::contentsOf($directory . '/lexicon.tsv'),
            self::contentsOf($directory . '/verbs.tsv'),
            $meta['rarest'],
            array_combine(array_map(intval(...), array_keys($floors)), $floors),
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

    public function nounClassFloorOfLength(int $length): int
    {
        return $this->nounClassFloorByLength[$length] ?? $this->rarestClass + 1;
    }

    public function isVerbStem(string $presentStem): bool
    {
        return null !== $this->verbs->valueOf($presentStem);
    }

    public function infinitiveStemOf(string $presentStem): string
    {
        return $this->verbs->valueOf($presentStem) ?? throw UnknownVerbStemException::forStem($presentStem);
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
