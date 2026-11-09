<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Support\MastodonSearchPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class MastodonSearchPageTest extends TestCase
{
    public function testEveryStatusButAReplyBecomesAnEntry(): void
    {
        $page = MastodonSearchPage::of([self::answer([
            'accounts' => [],
            'hashtags' => [],
            'statuses' => [self::searchStatus('111', null), self::searchStatus('112', '100')],
        ])], 'rss reader');

        self::assertSame(['https://mastodon.example/@amy/111'], array_map(
            static fn (ParsedEntryModel $entry): ?string => $entry->url,
            $page->parsed->entries,
        ));
        self::assertSame([], $page->selfReplies);
    }

    public function testThePageNamesTheNewestStatusItKept(): void
    {
        $page = MastodonSearchPage::of([self::answer(['statuses' => [self::searchStatus('111', null)]])], 'rss');

        self::assertSame('111', $page->newestId);
    }

    public function testAnAnswerWithoutStatusesIsAnUnreachableInstance(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        MastodonSearchPage::of([self::answer(['accounts' => []])], 'rss');
    }

    public function testAHashtagLinkInTheContentMatchesAsText(): void
    {
        $status = self::searchStatus('111', null);
        $status['content'] = '<p>Best <a href="https://mastodon.example/tags/rssreader" class="mention hashtag">'
            . '#<span>RSSReader</span></a> &amp; more</p>';

        $page = MastodonSearchPage::of([self::answer(['statuses' => [$status]])], 'rssreader');

        self::assertCount(1, $page->parsed->entries);
    }

    public function testAStatusMatchingOnlyInAMediaDescriptionIsLeftOut(): void
    {
        $status = self::searchStatus('111', null);
        $status['content'] = '<p>Look at this</p>';
        $status['media_attachments'] = [[
            'id' => '9',
            'type' => 'image',
            'url' => 'https://files.example/a.png',
            'description' => 'Screenshot of an RSS reader',
        ]];

        $page = MastodonSearchPage::of([self::answer(['statuses' => [$status]])], '"rss reader"');

        self::assertSame([], $page->parsed->entries);
    }

    public function testAStatusMatchingInTheStatusItQuotesIsKept(): void
    {
        $status = self::searchStatus('111', null);
        $status['content'] = '<p>Same here</p>';
        $status['quote'] = ['state' => 'accepted', 'quoted_status' => self::searchStatus('90', null)];

        $page = MastodonSearchPage::of([self::answer(['statuses' => [$status]])], '"rss reader"');

        self::assertCount(1, $page->parsed->entries);
    }

    public function testABoostMatchesByTheTextOfTheStatusItBoosts(): void
    {
        $boost = self::searchStatus('111', null);
        $boost['content'] = '';
        $boost['reblog'] = self::searchStatus('90', null);

        $page = MastodonSearchPage::of([self::answer(['statuses' => [$boost]])], '"rss reader"');

        self::assertCount(1, $page->parsed->entries);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function authorFilters(): iterable
    {
        yield 'a local account by its acct' => ['rss from:amy', ['111']];
        yield 'a local account with its instance' => ['rss from:@Amy@mastodon.example', ['111']];
        yield 'a remote account by its acct' => ['rss from:bob@other.example', ['112']];
        yield 'an excluded remote account' => ['rss -from:bob@other.example', ['111']];
        yield 'a remote account by its name alone' => ['rss from:bob', []];
    }

    /** @param list<string> $kept */
    #[DataProvider('authorFilters')]
    public function testAFromFilterIsCheckedAgainstTheAuthorsAcct(string $query, array $kept): void
    {
        $remote = self::searchStatus('112', null);
        $remote['account'] = ['id' => '8', 'acct' => 'bob@other.example', 'url' => 'https://other.example/@bob'];

        $answer = self::answer(['statuses' => [self::searchStatus('111', null), $remote]]);

        $page = MastodonSearchPage::of([$answer], $query);

        self::assertSame($kept, array_map(
            static fn (ParsedEntryModel $entry): string => basename((string) $entry->url),
            $page->parsed->entries,
        ));
    }

    /** @param array<mixed> $body */
    private static function answer(array $body): ApiResponseModel
    {
        return new ApiResponseModel(Response::HTTP_OK, json_encode($body, \JSON_THROW_ON_ERROR), []);
    }

    /** @return array<string, mixed> */
    private static function searchStatus(string $id, ?string $inReplyToId): array
    {
        return [
            'id' => $id,
            'uri' => 'https://mastodon.example/users/amy/statuses/' . $id,
            'url' => 'https://mastodon.example/@amy/' . $id,
            'created_at' => '2026-11-07T10:00:00.000Z',
            'content' => '<p>Which RSS reader?</p>',
            'in_reply_to_id' => $inReplyToId,
            'account' => [
                'id' => '7',
                'acct' => 'amy',
                'display_name' => 'Amy',
                'url' => 'https://mastodon.example/@amy',
            ],
        ];
    }
}
