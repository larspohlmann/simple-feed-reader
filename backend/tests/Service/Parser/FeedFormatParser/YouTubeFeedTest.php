<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\FeedFormatParser;

use App\Tests\Support\FeedFormatParsers;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class YouTubeFeedTest extends TestCase
{
    use ReadsFixtures;

    public function testAVideoEntryCarriesItsDescriptionAsTheBody(): void
    {
        $entry = FeedFormatParsers::feed($this->fixture('youtube/channel-videos.xml'))->entries[0];

        self::assertNotNull($entry->contentHtml);
        self::assertStringStartsWith('<p>', $entry->contentHtml);
        self::assertStringContainsString(
            '<a href="https://screencrushmerch.com/pages/subscribe">',
            $entry->contentHtml,
        );
    }
}
