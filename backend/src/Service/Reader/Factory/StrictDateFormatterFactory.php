<?php

declare(strict_types=1);

namespace App\Service\Reader\Factory;

final readonly class StrictDateFormatterFactory implements DateFormatterFactoryInterface
{
    public function build(string $locale, int $style): \IntlDateFormatter
    {
        $formatter = new \IntlDateFormatter(
            $locale,
            $style,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
        );
        $formatter->setLenient(false);

        return $formatter;
    }
}
