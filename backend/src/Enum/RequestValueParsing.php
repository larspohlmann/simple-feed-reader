<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

trait RequestValueParsing
{
    /**
     * @throws ValidationException naming $field when the value names no case
     */
    private static function caseOrRefusal(string $value, string $field): self
    {
        $case = self::tryFrom($value);

        if (null !== $case && \in_array($case, self::requestCases(), true)) {
            return $case;
        }

        throw new ValidationException([
            $field => [\sprintf('Unknown %s. Use one of: %s.', $field, self::valueList())],
        ]);
    }

    /** @return list<self> the cases a request may name */
    private static function requestCases(): array
    {
        return self::cases();
    }

    private static function valueList(): string
    {
        return implode(', ', array_map(static fn (self $case): string => $case->value, self::requestCases()));
    }
}
