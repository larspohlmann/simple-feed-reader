<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum NetworkFeedKind: string
{
    use RequestValueParsing;

    case Profile = 'profile';

    /**
     * @throws ValidationException when the value names no feed kind
     */
    public static function fromRequestValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::caseOrRefusal($value, 'feedKind');
    }
}
