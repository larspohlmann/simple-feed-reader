<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\MixcloudJsonParser;
use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudJsonParserTest extends TestCase
{
    use ReadsFixtures;

    private function parse(string $body): ParsedFeedModel
    {
        return (new MixcloudJsonParser())->parse($body);
    }

    private function recorded(): ParsedFeedModel
    {
        return $this->parse($this->fixture('mixcloud/cloudcasts.json'));
    }

    public function testTheFeedIsNamedAndLinkedAfterTheUser(): void
    {
        $feed = $this->recorded();

        self::assertSame('John Digweed', $feed->title);
        self::assertSame('https://www.mixcloud.com/johndigweed/', $feed->siteUrl);
        self::assertNull($feed->description);
        self::assertNull($feed->imageUrl);
    }

    public function testAnExclusiveShowNeverBecomesAnEntry(): void
    {
        $guids = array_map(
            static fn (ParsedEntryModel $entry): string => $entry->guid,
            $this->recorded()->entries,
        );

        self::assertSame([
            '/johndigweed/transitions-with-john-digweed-and-tigerhook/',
            '/johndigweed/transitions-john-digweed-and-martin-garcia/',
        ], $guids);
    }

    public function testMapsAShow(): void
    {
        $entry = $this->recorded()->entries[0];

        self::assertSame(
            'https://www.mixcloud.com/johndigweed/transitions-with-john-digweed-and-tigerhook/',
            $entry->url,
        );
        self::assertSame('Transitions with John Digweed and Tigerhook', $entry->headline->text);
        self::assertFalse($entry->headline->derived);
        self::assertSame('John Digweed', $entry->author);
        self::assertNull($entry->summary);
        self::assertNull($entry->contentHtml);
        self::assertSame(
            'https://thumbnailer.mixcloud.com/unsafe/600x600/extaudio/0/0/8/3/13e4-324d-45f4-bd8f-70c23dd0781f',
            $entry->media->image?->url,
        );
        self::assertEquals(
            [new ParsedCategoryModel('Techno'), new ParsedCategoryModel('Dub techno')],
            $entry->categories,
        );
    }

    public function testAnOffsetCreationTimeIsStoredAsUtc(): void
    {
        $publishedAt = $this->recorded()->entries[0]->publishedAt;

        self::assertNotNull($publishedAt);
        self::assertSame('2026-10-23T15:00:14+00:00', $publishedAt->format('c'));
    }

    public function testAShowWithoutTheOptionalFieldsStillBecomesAnEntry(): void
    {
        $entry = $this->recorded()->entries[1];

        self::assertSame('Transitions with John Digweed and Martin Garcia', $entry->headline->text);
        self::assertNull($entry->media->image);
        self::assertSame([], $entry->categories);
        self::assertNull($entry->publishedAt);
        self::assertSame('John Digweed', $entry->author);
    }

    public function testAShowMarkedNotExclusiveIsAnEntry(): void
    {
        $body = '{"data":[{"key":"/dj/free/","url":"https://www.mixcloud.com/dj/free/","name":"Free",'
            . '"is_exclusive":false}]}';

        self::assertCount(1, $this->parse($body)->entries);
    }

    /** @return iterable<string, array{string}> */
    public static function showsMissingARequiredField(): iterable
    {
        yield 'no key' => ['{"url":"https://www.mixcloud.com/dj/a/","name":"A"}'];
        yield 'no url' => ['{"key":"/dj/a/","name":"A"}'];
        yield 'no name' => ['{"key":"/dj/a/","url":"https://www.mixcloud.com/dj/a/"}'];
        yield 'a blank name' => ['{"key":"/dj/a/","url":"https://www.mixcloud.com/dj/a/","name":"  "}'];
        yield 'a key that is no string' => ['{"key":7,"url":"https://www.mixcloud.com/dj/a/","name":"A"}'];
        yield 'a show that is no object' => ['"junk"'];
    }

    #[DataProvider('showsMissingARequiredField')]
    public function testAShowMissingAKeyUrlOrNameIsSkipped(string $show): void
    {
        $body = '{"data":[' . $show . ',{"key":"/dj/b/","url":"https://www.mixcloud.com/dj/b/","name":"B"}]}';

        $entries = $this->parse($body)->entries;

        self::assertCount(1, $entries);
        self::assertSame('/dj/b/', $entries[0]->guid);
    }

    public function testAnEmptyListIsAnEmptyUntitledFeed(): void
    {
        $feed = $this->parse('{"data":[],"paging":{},"name":"Nobody\'s Cloudcasts"}');

        self::assertSame([], $feed->entries);
        self::assertNull($feed->title);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNoCloudcastsList(): iterable
    {
        yield 'an empty body' => ['   '];
        yield 'text that is no JSON' => ['not json at all'];
        yield 'a JSON null' => ['null'];
        yield 'a JSON list' => ['[]'];
        yield 'an API error' => ['{"error":{"type":"NotFound","message":"Not found"}}'];
        yield 'a data object, not a list' => ['{"data":{"key":"/dj/a/"}}'];
    }

    #[DataProvider('bodiesThatAreNoCloudcastsList')]
    public function testABodyThatIsNoCloudcastsListThrows(string $body): void
    {
        $this->expectException(FeedParseException::class);

        $this->parse($body);
    }
}
