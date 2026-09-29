<?php

declare(strict_types=1);

namespace App\Service\Reader\Factory;

interface DateFormatterFactoryInterface
{
    public function build(string $locale, int $style): \IntlDateFormatter;
}
