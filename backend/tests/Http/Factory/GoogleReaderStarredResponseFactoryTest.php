<?php

declare(strict_types=1);

namespace App\Tests\Http\Factory;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Http\Factory\GoogleReaderStarredResponseFactory;
use App\Service\Library\SavedListExport\Model\SavedListEntryModel;
use App\Service\Library\SavedListExport\Model\SavedListOriginModel;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GoogleReaderStarredResponseFactoryTest extends TestCase
{
    public function testInvalidUtf8InContentIsSubstitutedAndTheDocumentParses(): void
    {
        $factory = new GoogleReaderStarredResponseFactory(new MockClock('2026-07-01 12:00:00'));
        $scope = new SavedListScopeModel(EntryView::Kept, null, null, null, ListOrder::NewestFirst);
        $response = $factory->stream($scope, $this->batches("ok \xB1 end"));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['items']);
        self::assertIsArray($document['items'][0]);
        self::assertIsArray($document['items'][0]['content']);
        self::assertSame("ok \u{FFFD} end", $document['items'][0]['content']['content']);
    }

    /** @return \Generator<int, list<SavedListEntryModel>> */
    private function batches(string $contentHtml): \Generator
    {
        yield [new SavedListEntryModel(
            'guid-1',
            'https://export.example/1',
            'Title',
            null,
            $contentHtml,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new SavedListOriginModel('Feed', 'https://export.example/feed.xml', null),
            [],
        )];
    }
}
