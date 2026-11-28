<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ForYouStreamTuning;
use App\Enum\ForYouStreamPrecision;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

final class ForYouStreamTuningMappingTest extends TestCase
{
    public function testThePrecisionColumnDefaultIsTheEnumDefault(): void
    {
        $column = (new \ReflectionProperty(ForYouStreamTuning::class, 'precision'))
            ->getAttributes(Column::class)[0]->newInstance();

        self::assertSame(ForYouStreamPrecision::DEFAULT->value, $column->options['default']);
    }
}
