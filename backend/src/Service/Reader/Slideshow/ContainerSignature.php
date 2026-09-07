<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

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
        $tokens = self::tokenize($classAttribute ?? '');

        return $tokens === [] ? null : new self($tokens);
    }

    public function toSelector(): string
    {
        return '.' . implode('.', array_map(self::escapeLeadingDigit(...), $this->classTokens));
    }

    /** @return list<string> */
    private static function tokenize(string $classAttribute): array
    {
        return array_values(array_filter(explode(' ', $classAttribute), static fn (string $t): bool => $t !== ''));
    }

    /** A CSS class cannot start with a digit; escape it as its hex code point. */
    private static function escapeLeadingDigit(string $token): string
    {
        if (preg_match('/^-?\d/', $token) !== 1) {
            return $token;
        }

        $offset = $token[0] === '-' ? 1 : 0;

        return substr($token, 0, $offset) . sprintf('\\%x ', ord($token[$offset])) . substr($token, $offset + 1);
    }
}
