<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;

/**
 * The class tokens of the original carousel element, so the inserter can find
 * and remove it from the cleaned body when it survived extraction — otherwise
 * the recreated slideshow would sit beside the publisher's broken original.
 */
final readonly class ContainerSignature
{
    /** @param non-empty-list<string> $classTokens */
    private function __construct(private array $classTokens)
    {
    }

    public static function fromClassAttribute(?string $classAttribute): ?self
    {
        $tokens = array_values(array_filter(explode(' ', $classAttribute ?? ''), static fn (string $t): bool => $t !== ''));

        return $tokens === [] ? null : new self($tokens);
    }

    public function matches(Element $element): bool
    {
        $present = array_filter(explode(' ', $element->getAttribute('class') ?? ''));
        foreach ($this->classTokens as $token) {
            if (!in_array($token, $present, true)) {
                return false;
            }
        }

        return true;
    }
}
