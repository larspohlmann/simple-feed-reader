<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class SocialTokenScopeMissingException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $missingScope)
    {
        parent::__construct($message);
    }
}
