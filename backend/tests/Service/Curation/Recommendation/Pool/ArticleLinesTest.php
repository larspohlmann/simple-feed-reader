<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Pool;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Repository\LinkCardRepository;
use App\Repository\TitledEntry;
use App\Service\Curation\Recommendation\Pool\ArticleLines;
use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;

final class ArticleLinesTest extends DbTestCase
{
    public function testAPostCarriesTheStoryItsCardShares(): void
    {
        $post = $this->entry($this->socialFeed(), 'post', null);
        $this->shareStory($post, 'https://news.example.com/story');

        $line = $this->lines([$post])[0];
        self::assertSame('Story', $line->sharedArticle?->title);
        self::assertSame((new UrlNormalizer())->hash('https://news.example.com/story'), $line->articleKey());
        self::assertFalse($line->isAnOutletArticle());
    }

    public function testAnOutletArticleKeysOnItsOwnUrl(): void
    {
        $article = $this->entry($this->outletFeed(), 'article', 'own-hash');
        $this->shareStory($article, 'https://news.example.com/story');

        $line = $this->lines([$article])[0];

        self::assertNull($line->sharedArticle);
        self::assertSame('own-hash', $line->articleKey());
        self::assertTrue($line->isAnOutletArticle());
    }

    public function testAPostWithoutACardHasNoArticleKey(): void
    {
        $post = $this->entry($this->socialFeed(), 'post', null);

        $line = $this->lines([$post])[0];

        self::assertNull($line->articleKey());
        self::assertFalse($line->isAnOutletArticle());
    }

    public function testLinesKeepTheirInputOrder(): void
    {
        $feed = $this->outletFeed();
        $first = $this->entry($feed, 'first', 'hash-first');
        $second = $this->entry($feed, 'second', 'hash-second');
        $third = $this->entry($feed, 'third', 'hash-third');

        $lines = $this->lines([$third, $first, $second]);

        self::assertSame(
            [$third->requireId(), $first->requireId(), $second->requireId()],
            array_map(static fn (ArticleLineModel $line): int => $line->entryId, $lines),
        );
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<ArticleLineModel>
     */
    private function lines(array $entries): array
    {
        $articleLines = new ArticleLines($this->linkCards());

        return $articleLines->loadLines(array_map(
            static fn (Entry $entry): TitledEntry => new TitledEntry($entry, 'Feed'),
            $entries,
        ));
    }

    private function socialFeed(): Feed
    {
        $feed = new Feed('https://bsky.app/profile/alice.bsky.social/rss');
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function outletFeed(): Feed
    {
        $feed = new Feed('https://example.com/outlet.xml');
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid, ?string $urlHash): Entry
    {
        $moment = new \DateTimeImmutable('2026-10-10 08:00:00');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, $guid, $moment, $moment, $urlHash);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function shareStory(Entry $entry, string $url): void
    {
        $content = new LinkCardContent($url, 'Story', 'About', null, 'Example');
        $this->linkCards()->append($entry, [SharedLinks::of($this->entityManager, $content)]);
        $this->entityManager->flush();
    }

    private function linkCards(): LinkCardRepository
    {
        $repository = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $repository);

        return $repository;
    }
}
