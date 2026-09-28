<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\FeedBodyParser;
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
}
