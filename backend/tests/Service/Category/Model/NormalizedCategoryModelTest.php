<?php

declare(strict_types=1);

namespace App\Tests\Service\Category\Model;

use App\Service\Category\Model\NormalizedCategoryModel;
use PHPUnit\Framework\TestCase;

final class NormalizedCategoryModelTest extends TestCase
{
    public function testIdentityDistinguishesWhereTheKeyEndsAndTheSchemeStarts(): void
    {
        $first = new NormalizedCategoryModel('ab', 'AB', 'c');
        $second = new NormalizedCategoryModel('a', 'A', 'bc');

        self::assertNotSame($first->identity(), $second->identity());
    }

    public function testIdentityIsSameForIdenticalKeyAndScheme(): void
    {
        $first = new NormalizedCategoryModel('politics', 'Politics', 'https://a.test');
        $second = new NormalizedCategoryModel('politics', 'Different Label', 'https://a.test');

        self::assertSame($first->identity(), $second->identity());
    }
}
