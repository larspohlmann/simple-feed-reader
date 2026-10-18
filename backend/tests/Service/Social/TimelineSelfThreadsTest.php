<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ThreadPost;
use App\Enum\SourceFormat;
use App\Repository\ThreadPostRepository;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Social\Model\ThreadPostModel;
use App\Service\Social\Model\TimelineThreadsModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Service\Social\TimelineSelfThreads;
use App\Tests\DbTestCase;

final class TimelineSelfThreadsTest extends DbTestCase
{
    public function testAMastodonRootFoldsItsSelfRepliesWithTheirContent(): void
    {
        $feed = $this->feed();
        $page = MastodonTimelinePage::of([
            self::timelineStatus('102', '101'),
            self::timelineStatus('101', '100'),
            self::timelineStatus('100'),
        ]);

        $plan = $this->selfThreads()->plan($feed, $page);
        $this->foldIntoRootOf($feed, $plan->parsed->entries, $plan->threads);

        self::assertSame([self::uri('100')], self::guidsOf($plan->parsed->entries));
        self::assertSame(['<p>Status 101</p>', '<p>Status 102</p>'], $this->threadHtmlOf(self::uri('100')));
    }

    public function testAPageWithoutSelfRepliesIsPlannedAsItIs(): void
    {
        $page = MastodonTimelinePage::of([self::timelineStatus('100')]);

        $plan = $this->selfThreads()->plan($this->feed(), $page);

        self::assertSame($page->parsed, $plan->parsed);
        self::assertSame([], $plan->threads->placed);
    }

    public function testOfTwoRepliesToTheSamePostTheOlderTakesThePosition(): void
    {
        $page = MastodonTimelinePage::of([
            self::timelineStatus('102', '100'),
            self::timelineStatus('101', '100'),
            self::timelineStatus('100'),
        ]);

        $plan = $this->selfThreads()->plan($this->feed(), $page);

        self::assertCount(1, $plan->threads->placed);
        self::assertSame(1, $plan->threads->placed[0]->position);
        self::assertSame('101', $plan->threads->placed[0]->post->string('id'));
    }

    public function testAChainDeeperThanThreeRepliesDropsTheFourth(): void
    {
        $feed = $this->feed();
        $page = MastodonTimelinePage::of([
            self::timelineStatus('104', '103'),
            self::timelineStatus('103', '102'),
            self::timelineStatus('102', '101'),
            self::timelineStatus('101', '100'),
            self::timelineStatus('100'),
        ]);

        $plan = $this->selfThreads()->plan($feed, $page);
        $this->foldIntoRootOf($feed, $plan->parsed->entries, $plan->threads);

        self::assertSame([self::uri('100')], self::guidsOf($plan->parsed->entries));
        self::assertSame(
            ['<p>Status 101</p>', '<p>Status 102</p>', '<p>Status 103</p>'],
            $this->threadHtmlOf(self::uri('100')),
        );
        self::assertSame(3, $this->entityManager->getRepository(ThreadPost::class)->count());
    }

    /** @param list<ParsedEntryModel> $entries the plan's: only the root */
    private function foldIntoRootOf(Feed $feed, array $entries, TimelineThreadsModel $threads): void
    {
        $parsedRoot = $entries[0];
        $now = new \DateTimeImmutable('2026-10-10 17:00:00');
        $root = new Entry($feed, $parsedRoot->guid, $parsedRoot->url, 'Status 100', $now, $now);
        $this->entityManager->persist($root);

        $this->selfThreads()->fold($feed, $threads, [$root]);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function threadHtmlOf(string $rootGuid): array
    {
        $root = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => $rootGuid]);
        self::assertInstanceOf(Entry::class, $root);
        $threadPosts = self::getContainer()->get(ThreadPostRepository::class);
        self::assertInstanceOf(ThreadPostRepository::class, $threadPosts);

        return array_map(
            static fn (ThreadPostModel $post): string => $post->html,
            $threadPosts->byEntryIds([$root->requireId()])[$root->requireId()] ?? [],
        );
    }

    private function selfThreads(): TimelineSelfThreads
    {
        $selfThreads = self::getContainer()->get(TimelineSelfThreads::class);
        self::assertInstanceOf(TimelineSelfThreads::class, $selfThreads);

        return $selfThreads;
    }

    private function feed(): Feed
    {
        $feed = new Feed('social:mastodon:1:home');
        $feed->setSourceFormat(SourceFormat::MASTODON_API);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    /**
     * @param list<ParsedEntryModel> $entries
     *
     * @return list<string>
     */
    private static function guidsOf(array $entries): array
    {
        return array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $entries);
    }

    private static function timelineStatus(string $id, ?string $parentId = null): JsonNodeModel
    {
        return JsonNodeModel::of([
            'id' => $id,
            'uri' => self::uri($id),
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => sprintf('2026-10-10T16:%02d:00.000Z', (int) $id - 100),
            'content' => '<p>Status ' . $id . '</p>',
            'spoiler_text' => '',
            'in_reply_to_id' => $parentId,
            'in_reply_to_account_id' => $parentId === null ? null : '7',
            'account' => [
                'id' => '7',
                'acct' => 'bob',
                'username' => 'bob',
                'display_name' => 'Bob',
                'url' => 'https://mastodon.example/@bob',
            ],
            'media_attachments' => [],
            'reblog' => null,
            'card' => null,
        ]);
    }

    private static function uri(string $id): string
    {
        return 'https://mastodon.example/users/bob/statuses/' . $id;
    }
}
