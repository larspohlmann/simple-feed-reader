<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\CatalogCategoryRequest;
use PHPUnit\Framework\TestCase;

final class CatalogCategoryRequestTest extends TestCase
{
    public function testToDetailsCarriesEveryField(): void
    {
        $details = (new CatalogCategoryRequest('tech', 'Tech', 'bolt', '#112233', false, true))->toDetails();

        self::assertSame(
            [
                'key' => 'tech',
                'name' => 'Tech',
                'icon' => 'bolt',
                'color' => '#112233',
                'enabled' => false,
                'locked' => true,
            ],
            get_object_vars($details),
        );
    }
}
