<?php

declare(strict_types=1);

namespace App\Service\Tag\Exception;

use App\Entity\Tag;

final class InvalidTagParentException extends \RuntimeException
{
    public static function unknown(): self
    {
        return new self('The parent tag does not exist.');
    }

    public static function tooDeep(): self
    {
        return new self(sprintf('A tag can sit at most %d levels below the top.', Tag::MAX_DEPTH));
    }

    public static function underItsOwnChild(): self
    {
        return new self('A tag cannot move under one of its own child tags.');
    }

    public static function itself(): self
    {
        return new self('A tag cannot be its own parent.');
    }
}
