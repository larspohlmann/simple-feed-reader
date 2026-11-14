<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\MixcloudJsonParser;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedBodyParser\MixcloudBodyParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class FeedBodyParserTest extends TestCase
{
    public function testALocatorWithoutTheXmlParserIsAWiringError(): void
    {
        $parser = new FeedBodyParser(new ServiceLocator([]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No feed body parser is wired for "xml".');

        $parser->parse(new Feed('https://example.com/feed'), '<rss/>');
    }

    public function testParsesABodyInTheFormatItIsGivenWithoutAFeedRow(): void
    {
        $parser = new FeedBodyParser(new ServiceLocator([
            SourceFormat::MIXCLOUD_API => static fn (): MixcloudBodyParser => new MixcloudBodyParser(
                new MixcloudJsonParser(),
            ),
        ]));

        $parsed = $parser->parseAs(
            SourceFormat::MIXCLOUD_API,
            '{"data":[]}',
            'https://api.mixcloud.com/dj/cloudcasts/',
        );

        self::assertSame([], $parsed->entries);
    }
}
