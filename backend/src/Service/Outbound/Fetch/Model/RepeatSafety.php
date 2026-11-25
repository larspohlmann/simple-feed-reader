<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Model;

/** Whether a request may be sent again once it may have reached the server. */
enum RepeatSafety
{
    private const array REPEATABLE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    case Repeatable;
    case Write;

    public static function fromMethod(string $method): self
    {
        return \in_array(strtoupper($method), self::REPEATABLE_METHODS, true) ? self::Repeatable : self::Write;
    }
}
