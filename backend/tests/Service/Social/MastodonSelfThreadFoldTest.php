<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\SocialNetwork;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Social\MastodonContextReplies;
use App\Service\Social\Model\ThreadPostModel;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Service\Social\ReplyThreadSource\MastodonReplyThreadSource;
use App\Tests\DbTestCase;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SelfThreadEnrichers;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MastodonSelfThreadFoldTest extends DbTestCase
{
    private const string POST = 'https://mastodon.social/@Gargron/110';
    private const string PUBLIC_CONTEXT = 'GET https://mastodon.social/api/v1/statuses/110/context';

    public function testTheAuthorsReplyIsTakenOverAnOlderStrangerAndTheChainStopsWhereOnlyAStrangerAnswers(): void
    {
        $clock = new MockClock('2026-10-16 12:00:00', 'UTC');
        $entry = $this->queuedEntry($clock->now());
        $http = new RoutedHttpClient([self::PUBLIC_CONTEXT => new MockResponse(self::fixture())]);
        $accountApi = SocialApiWiring::mastodonAccountApi($http->client());
        $source = new MastodonReplyThreadSource(
            new MastodonPostResolver($accountApi),
            $accountApi,
            SocialApiWiring::mastodonApiClient($http->client()),
            new MastodonContextReplies(new EntrySanitizer(new TrailingBlankRemover())),
        );

        SelfThreadEnrichers::build($this->entityManager, $clock, $source, new NullLogger())->enrich($entry->getFeed());

        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertSame(
            ['https://mastodon.social/@Gargron/300', 'https://mastodon.social/@Gargron/302'],
            array_map(static fn (ThreadPostModel $post): ?string => $post->url, $posts[$entry->requireId()]),
        );
        self::assertSame([self::PUBLIC_CONTEXT], $http->requestLines());
    }

    private function queuedEntry(\DateTimeImmutable $now): Entry
    {
        $feed = new Feed('https://mastodon.social/@Gargron.rss');
        $feed->classifyAs(SocialNetwork::Mastodon);
        $this->entityManager->persist($feed);
        $entry = new Entry($feed, self::POST, self::POST, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::SelfThread, $now));
        $this->entityManager->flush();

        return $entry;
    }

    private static function fixture(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/social/mastodon-self-thread-context.json');
        Assert::assertIsString($contents);

        return $contents;
    }
}
