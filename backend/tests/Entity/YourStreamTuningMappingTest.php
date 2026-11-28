<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\YourStreamTuning;
use App\Enum\YourStreamPrecision;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

final class YourStreamTuningMappingTest extends TestCase
{
    public function testThePrecisionColumnDefaultIsTheEnumDefault(): void
    {
        $column = (new \ReflectionProperty(YourStreamTuning::class, 'precision'))
            ->getAttributes(Column::class)[0]->newInstance();

        self::assertSame(YourStreamPrecision::DEFAULT->value, $column->options['default']);
    }
}
