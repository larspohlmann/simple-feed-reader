<?php

declare(strict_types=1);

namespace App\Tests\Service\Category;

use App\Service\Category\NormalizedCategory;
use PHPUnit\Framework\TestCase;

final class NormalizedCategoryTest extends TestCase
{
    public function testIdentityDistinguishesWhereTheKeyEndsAndTheSchemeStarts(): void
    {
        $first = new NormalizedCategory('ab', 'AB', 'c');
        $second = new NormalizedCategory('a', 'A', 'bc');

        self::assertNotSame($first->identity(), $second->identity());
    }

    public function testIdentityIsSameForIdenticalKeyAndScheme(): void
    {
        $first = new NormalizedCategory('politics', 'Politics', 'https://a.test');
        $second = new NormalizedCategory('politics', 'Different Label', 'https://a.test');

        self::assertSame($first->identity(), $second->identity());
    }
}
