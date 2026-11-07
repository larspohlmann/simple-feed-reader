<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Support\BlueskySearchPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySearchPageTest extends TestCase
{
    public function testEveryPostButAReplyBecomesAnEntry(): void
    {
        $page = BlueskySearchPage::of(self::answer(['posts' => [
            self::post('at://did:plc:amy/app.bsky.feed.post/1', 'Which RSS reader do you use?'),
            self::post('at://did:plc:bob/app.bsky.feed.post/2', 'NetNewsWire!', reply: true),
        ]]), 'did:plc:me');

        self::assertSame(
            ['at://did:plc:amy/app.bsky.feed.post/1'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
        self::assertSame([], $page->selfReplies);
    }

    public function testAPostKeepsItsWebUrlAndItsPostViewForItsEmbed(): void
    {
        $uri = 'at://did:plc:amy/app.bsky.feed.post/1';

        $page = BlueskySearchPage::of(self::answer(['posts' => [self::post($uri, 'Hello')]]), 'did:plc:me');

        self::assertSame('https://bsky.app/profile/did:plc:amy/post/1', $page->parsed->entries[0]->url);
        self::assertSame($uri, $page->knownShares->blueskyPostFor($uri)?->string('uri'));
    }

    public function testAnAnswerWithoutPostsIsAnUnreachableBluesky(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        BlueskySearchPage::of(self::answer(['cursor' => 'x']), 'did:plc:me');
    }

    /** @param array<mixed> $body */
    private static function answer(array $body): ApiResponseModel
    {
        return new ApiResponseModel(Response::HTTP_OK, json_encode($body, \JSON_THROW_ON_ERROR), []);
    }

    /** @return array<string, mixed> */
    private static function post(string $uri, string $text, bool $reply = false): array
    {
        $record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-11-07T10:00:00.000Z'];
        if ($reply) {
            $parent = ['uri' => 'at://did:plc:amy/app.bsky.feed.post/1', 'cid' => 'c1'];
            $record['reply'] = ['parent' => $parent, 'root' => $parent];
        }

        return [
            'uri' => $uri,
            'cid' => 'c2',
            'author' => ['did' => 'did:plc:amy', 'handle' => 'amy.bsky.social', 'displayName' => 'Amy'],
            'record' => $record,
            'indexedAt' => '2026-11-07T10:00:01.000Z',
        ];
    }
}
