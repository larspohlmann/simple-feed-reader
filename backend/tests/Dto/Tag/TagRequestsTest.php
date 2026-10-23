<?php

declare(strict_types=1);

namespace App\Tests\Dto\Tag;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use PHPUnit\Framework\TestCase;

final class TagRequestsTest extends TestCase
{
    public function testACreateRequestCarriesTheNameTheColourTheIconAndTheParent(): void
    {
        $details = (new CreateTagRequest('News', '#ff8800', 'star', 7))->toDetails();

        self::assertSame(
            ['name' => 'News', 'color' => '#ff8800', 'icon' => 'star', 'parentId' => 7],
            get_object_vars($details),
        );
    }

    public function testAnUpdateRequestCarriesTheNameTheColourTheIconAndTheParent(): void
    {
        $details = (new UpdateTagRequest('Tech', '#000000', 'label', 7))->toDetails();

        self::assertSame(
            ['name' => 'Tech', 'color' => '#000000', 'icon' => 'label', 'parentId' => 7],
            get_object_vars($details),
        );
    }
}
