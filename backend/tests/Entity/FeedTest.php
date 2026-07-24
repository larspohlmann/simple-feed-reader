<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    public function testSourceFormatDefaultsToXmlAndIsMutable(): void
    {
        $feed = new Feed('https://example.com/page');
        self::assertSame('xml', $feed->getSourceFormat());
        $feed->setSourceFormat('scraped');
        self::assertSame('scraped', $feed->getSourceFormat());
    }
}
