<?php

declare(strict_types=1);

namespace App\Service\Image\Exception;

class GuardedImageUnavailableException extends \RuntimeException
{
    public static function from(\Throwable $cause): self
    {
        return new self($cause->getMessage(), 0, $cause);
    }
}
