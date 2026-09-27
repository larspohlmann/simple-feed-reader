<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Exception\MalformedOptionException;
use Symfony\Component\Console\Input\InputInterface;

final class ConsoleOption
{
    public static function text(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @throws MalformedOptionException */
    public static function wholeNumber(InputInterface $input, string $name): ?int
    {
        $value = self::text($input, $name);
        if ($value === null) {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new MalformedOptionException($name, $value);
        }

        return (int) $value;
    }

    /** @throws MalformedOptionException */
    public static function limit(InputInterface $input): ?int
    {
        $limit = self::wholeNumber($input, 'limit');

        return $limit === null ? null : max(1, $limit);
    }
}
