<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Exception\UnknownVerbStemException;

final readonly class GermanLexiconModel
{
    private int $rarestClass;

    /** @var array<int, int> */
    private array $medianNounClassByLength;

    /**
     * @param array<string, int> $nouns
     * @param array<string, int> $others
     * @param array<string, string> $verbs
     */
    public function __construct(
        private array $nouns,
        private array $others,
        private array $verbs,
    ) {
        $this->rarestClass = self::rarestClassOf($nouns + $others);
        $this->medianNounClassByLength = self::medianClassByLength($nouns);
    }

    public static function fromFile(mixed $loaded): self
    {
        if (!is_array($loaded)) {
            throw new \UnexpectedValueException('The German lexicon file must return an array.');
        }

        return new self(
            self::classes(self::section($loaded, 'nouns')),
            self::classes(self::section($loaded, 'others')),
            self::infinitiveStems(self::section($loaded, 'verbs')),
        );
    }

    public function isNoun(string $form): bool
    {
        return isset($this->nouns[$form]);
    }

    public function isKnown(string $form): bool
    {
        return isset($this->nouns[$form]) || isset($this->others[$form]);
    }

    public function frequencyClassOf(string $form): int
    {
        return $this->nouns[$form] ?? $this->others[$form] ?? $this->rarestClass + 1;
    }

    public function medianNounClassOfLength(int $length): int
    {
        return $this->medianNounClassByLength[$length] ?? $this->rarestClass + 1;
    }

    public function isVerbStem(string $presentStem): bool
    {
        return isset($this->verbs[$presentStem]);
    }

    public function infinitiveStemOf(string $presentStem): string
    {
        return $this->verbs[$presentStem] ?? throw UnknownVerbStemException::forStem($presentStem);
    }

    /**
     * @param array<string, int> $nouns
     * @return array<int, int>
     */
    private static function medianClassByLength(array $nouns): array
    {
        $classesByLength = [];
        foreach ($nouns as $noun => $class) {
            $classesByLength[mb_strlen((string) $noun)][] = $class;
        }

        return array_map(self::median(...), $classesByLength);
    }

    /** @param non-empty-list<int> $classes */
    private static function median(array $classes): int
    {
        sort($classes);

        return $classes[intdiv(count($classes), 2)];
    }

    /**
     * @param array<string, int> $classes
     */
    private static function rarestClassOf(array $classes): int
    {
        if ([] === $classes) {
            return 0;
        }

        return max($classes);
    }

    /**
     * @param array<mixed> $loaded
     * @return array<mixed>
     */
    private static function section(array $loaded, string $name): array
    {
        $section = $loaded[$name] ?? null;
        if (!is_array($section)) {
            throw new \UnexpectedValueException(sprintf('The German lexicon file has no "%s" section.', $name));
        }

        return $section;
    }

    /**
     * @param array<mixed> $section
     * @return array<string, int>
     */
    private static function classes(array $section): array
    {
        $classes = [];
        foreach ($section as $form => $class) {
            if (!is_int($class)) {
                throw new \UnexpectedValueException(sprintf('The class of "%s" is not an integer.', $form));
            }
            $classes[(string) $form] = $class;
        }

        return $classes;
    }

    /**
     * @param array<mixed> $section
     * @return array<string, string>
     */
    private static function infinitiveStems(array $section): array
    {
        $stems = [];
        foreach ($section as $presentStem => $infinitiveStem) {
            if (!is_string($infinitiveStem)) {
                throw new \UnexpectedValueException(sprintf('The stem of "%s" is not a string.', $presentStem));
            }
            $stems[(string) $presentStem] = $infinitiveStem;
        }

        return $stems;
    }
}
