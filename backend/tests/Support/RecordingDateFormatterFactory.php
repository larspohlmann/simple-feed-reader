<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\Factory\DateFormatterFactoryInterface;
use App\Service\Reader\Factory\StrictDateFormatterFactory;

final class RecordingDateFormatterFactory implements DateFormatterFactoryInterface
{
    /** @var list<string> */
    private array $built = [];

    public function build(string $locale, int $style): \IntlDateFormatter
    {
        $this->built[] = $locale . '|' . $style;

        return (new StrictDateFormatterFactory())->build($locale, $style);
    }

    /** @return list<string> */
    public function built(): array
    {
        return $this->built;
    }
}
