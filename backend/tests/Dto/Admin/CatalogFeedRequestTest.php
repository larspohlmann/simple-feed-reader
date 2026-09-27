<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\CatalogFeedRequest;
use App\Enum\SourceFormat;
use PHPUnit\Framework\TestCase;

final class CatalogFeedRequestTest extends TestCase
{
    public function testToDetailsCarriesEveryField(): void
    {
        $request = new CatalogFeedRequest(
            4,
            'Title',
            'https://feed.example.com/rss',
            'https://feed.example.com',
            'About',
            SourceFormat::SCRAPED,
            false,
            true,
        );

        self::assertSame(
            [
                'categoryId' => 4,
                'title' => 'Title',
                'url' => 'https://feed.example.com/rss',
                'siteUrl' => 'https://feed.example.com',
                'description' => 'About',
                'sourceFormat' => SourceFormat::SCRAPED,
                'enabled' => false,
                'locked' => true,
            ],
            get_object_vars($request->toDetails()),
        );
    }
}
