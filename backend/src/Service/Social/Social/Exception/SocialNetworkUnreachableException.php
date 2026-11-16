<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

class SocialNetworkUnreachableException extends \RuntimeException
{
    public static function from(\Throwable $previous): self
    {
        return new self($previous->getMessage(), 0, $previous);
    }
}
