<?php

declare(strict_types=1);

namespace App\Tests\Service\Sourcing\Discovery\Model;

use App\Service\Sourcing\Discovery\Model\DiscoveredFeedModel;
use App\Service\Sourcing\Discovery\Model\FeedCandidateModel;
use App\Service\Ingestion\Parser\Model\FeedSyntax;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use PHPUnit\Framework\TestCase;

final class FeedCandidateModelTest extends TestCase
{
    public function testADiscoveredFeedIsOfferedUnderItsFinalUrlTitleAndDialect(): void
    {
        $feed = new DiscoveredFeedModel(
            'https://example.com/feed.xml',
            new ParsedFeedModel('Example', null, null, null, [], syntax: FeedSyntax::Atom),
        );

        self::assertEquals(
            new FeedCandidateModel('https://example.com/feed.xml', 'Example', 'atom'),
            FeedCandidateModel::of($feed),
        );
    }

    public function testADocumentOfUnknownDialectIsOfferedAsAGuessedFeed(): void
    {
        $feed = new DiscoveredFeedModel('https://example.com/feed', new ParsedFeedModel(null, null, null, null, []));

        self::assertSame(FeedCandidateModel::GUESSED_FORMAT, FeedCandidateModel::of($feed)->format);
        self::assertNull(FeedCandidateModel::of($feed)->title);
    }
}
