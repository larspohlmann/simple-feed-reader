<?php

declare(strict_types=1);

namespace App\Service\Tag\Exception;

final class InvalidTagParentException extends \RuntimeException
{
    public static function unknown(): self
    {
        return new self('The parent tag does not exist.');
    }

    public static function parentIsAChild(): self
    {
        return new self('A parent tag cannot itself have a parent.');
    }

    public static function tagHasChildren(): self
    {
        return new self('A tag with child tags cannot have a parent.');
    }

    public static function itself(): self
    {
        return new self('A tag cannot be its own parent.');
    }
}
