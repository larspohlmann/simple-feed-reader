<?php

declare(strict_types=1);

namespace App\Tests\Service\Feed\Factory;

use App\Service\Feed\Factory\FeedFactory;
use PHPUnit\Framework\TestCase;

final class FeedFactoryTest extends TestCase
{
    public function testANewFeedTakesItsFormatAndItsTitle(): void
    {
        $feed = (new FeedFactory())->create('https://new.example/', 'scraped', 'New Site');

        self::assertSame('https://new.example/', $feed->getUrl());
        self::assertSame('scraped', $feed->getSourceFormat());
        self::assertSame('New Site', $feed->getTitle());
    }
}
