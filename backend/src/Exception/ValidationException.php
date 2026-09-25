<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors field name => messages */
    public function __construct(public readonly array $errors)
    {
    }
}
