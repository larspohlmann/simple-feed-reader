<?php

declare(strict_types=1);

namespace App\Command\Exception;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;

/** The console's own option error, so the application prints it and exits INVALID without a catch per command. */
final class MalformedOptionException extends InvalidOptionException
{
    public function __construct(string $option, string $value)
    {
        parent::__construct(
            \sprintf('The --%s option takes a whole number; "%s" is not one.', $option, $value),
            Command::INVALID,
        );
    }
}
