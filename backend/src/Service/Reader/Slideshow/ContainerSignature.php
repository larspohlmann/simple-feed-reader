<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;

/**
 * How to find the original carousel again in the cleaned body, so the inserter
 * can remove it — otherwise the recreated slideshow would sit beside the
 * publisher's broken original. The id is the sturdier key: readability merges a
 * wrapper chain onto one element, keeping the innermost id but the outermost
 * class, so a class-only signature loses the element the id still names.
 */
final readonly class ContainerSignature
{
    /** @param list<string> $classTokens */
    private function __construct(
        private array $classTokens,
        private ?string $id,
    ) {
    }

    public static function fromElement(Element $element): ?self
    {
        return self::from($element->getAttribute('class'), $element->getAttribute('id'));
    }

    public static function fromClassAttribute(?string $classAttribute): ?self
    {
        return self::from($classAttribute, null);
    }

    public function matches(Element $element): bool
    {
        if ($this->id !== null && $element->getAttribute('id') === $this->id) {
            return true;
        }

        return $this->classTokens !== [] && $this->carriesEveryClassToken($element);
    }

    private static function from(?string $classAttribute, ?string $id): ?self
    {
        $tokens = self::tokenize($classAttribute ?? '');
        $id = ($id === null || $id === '') ? null : $id;

        return $tokens === [] && $id === null ? null : new self($tokens, $id);
    }

    private function carriesEveryClassToken(Element $element): bool
    {
        $present = self::tokenize($element->getAttribute('class') ?? '');
        foreach ($this->classTokens as $token) {
            if (!in_array($token, $present, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function tokenize(string $classAttribute): array
    {
        return array_values(array_filter(explode(' ', $classAttribute), static fn (string $t): bool => $t !== ''));
    }
}
