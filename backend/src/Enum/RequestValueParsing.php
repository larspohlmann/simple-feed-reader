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
        return self::tryFrom($value) ?? throw new ValidationException([
            $field => [\sprintf('Unknown %s. Use one of: %s.', $field, self::valueList())],
        ]);
    }

    private static function valueList(): string
    {
        return implode(', ', array_map(static fn (self $case): string => $case->value, self::cases()));
    }
}
