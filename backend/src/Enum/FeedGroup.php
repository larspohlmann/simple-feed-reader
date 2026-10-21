<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum FeedGroup: string
{
    use RequestValueParsing;

    case Tags = 'tags';
    case Feeds = 'feeds';

    /**
     * @throws ValidationException when the value names no group
     */
    public static function fromRequestValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::caseOrRefusal($value, 'group');
    }
}
