<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Enum\ShareKind;
use App\Service\LinkCard\EntryCardWriter;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Model\QuotedTargetModel;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ShareLinkCardsWiring;

final class EntryCardWriterTest extends DbTestCase
{
    private const string QUOTE = '<p>RE: <a href="https://q.example/@a/1">https://q.example/@a/1</a></p>';
    private const string CHART = 'https://i.example.com/chart';

    private EntryCardWriter $writer;
    private Feed $feed;
    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = ShareLinkCardsWiring::cardWriter($this->entityManager);
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
        $this->feed->setSiteUrl('https://mastodon.example/@someone');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testTheNewCardsFollowTheStoredOnes(): void
    {
        $post = $this->post('<p>https://example.com/1 https://example.com/2 https://example.com/3</p>');
        $stored = [self::card('https://example.com/2')];
        $this->writeFlushed($post, new EntryCardsModel([], [self::pageCard('https://example.com/2')]));

        $this->writeFlushed($post, new EntryCardsModel(
            $stored,
            [self::pageCard('https://example.com/1'), self::pageCard('https://example.com/3')],
        ));

        self::assertSame(
            ['https://example.com/2', 'https://example.com/1', 'https://example.com/3'],
            $this->cardUrls($post),
        );
        self::assertSame([0, 1, 2], $this->positions($post));
    }

    public function testAPageWithoutACardLeavesNoGapInTheOrder(): void
    {
        $post = $this->post('<p>https://example.com/untitled https://example.com/found</p>');

        $this->writeFlushed($post, new EntryCardsModel(
            [],
            [new PageCardModel(null, null), self::pageCard('https://example.com/found')],
        ));

        self::assertSame(['https://example.com/found'], $this->cardUrls($post));
        self::assertSame([0], $this->positions($post));
    }

    public function testACardedQuoteLeavesTheBodyAndTheSummaryIsDerivedAgain(): void
    {
        $post = $this->post(self::QUOTE . '<p>Reposting for the morning crew</p>');
        $post->setSummary('RE: https://q.example/@a/1 Reposting for the morning crew');

        $this->writeFlushed($post, new EntryCardsModel([], [self::pageCard('https://q.example/@a/1')]));

        self::assertSame('<p>Reposting for the morning crew</p>', $post->getContentHtml());
        self::assertSame('Reposting for the morning crew', $post->getSummary());
    }

    public function testAQuoteOnlyPostEndsWithNoBodyAndNoSummary(): void
    {
        $post = $this->post(self::QUOTE);
        $post->setSummary('RE: https://q.example/@a/1');

        $this->writeFlushed($post, new EntryCardsModel([], [self::pageCard('https://q.example/@a/1')]));

        self::assertNull($post->getContentHtml());
        self::assertNull($post->getSummary());
    }

    public function testAQuoteWithoutACardKeepsItsLine(): void
    {
        $body = self::QUOTE . '<p>Look.</p>';
        $post = $this->post($body);
        $post->setSummary('RE: https://q.example/@a/1 Look.');

        $this->writeFlushed($post, new EntryCardsModel([], []));

        self::assertSame($body, $post->getContentHtml());
        self::assertSame('RE: https://q.example/@a/1 Look.', $post->getSummary());
    }

    public function testAQuotedPostLeavesItsQuoteLineUnderItsAddressAndMakesTheShareAQuote(): void
    {
        $address = 'https://m.example/users/b/statuses/9';
        $quote = new QuotedPostContent('https://m.example/@b/9', 'Bea', 'bea@m.example', null, null, 'Words', [], null);
        $post = $this->post('<p>Wow</p><p>RE: <a href="' . $address . '">' . $address . '</a></p>');

        $this->writeFlushed($post, EntryCardsModel::quoting(new QuotedTargetModel($address, $quote)));

        self::assertSame('<p>Wow</p>', $post->getContentHtml());
        self::assertSame('Wow', $post->getSummary());
        self::assertSame(ShareKind::Quote, $post->getShareKind());
        self::assertEquals($quote, $post->getQuotedPost());
        self::assertSame([], $this->cardUrls($post));
    }

    public function testATitledShareWhoseTargetIsAPictureBecomesAMediaShare(): void
    {
        $chart = $this->titledShare(self::CHART);

        $this->writeFlushed($chart, new EntryCardsModel([], [self::picture(self::CHART)]));

        self::assertSame(ShareKind::Media, $chart->getShareKind());
        self::assertEquals([new EntryMedium(self::CHART, 'image')], $chart->getMedia());
        self::assertSame([], $this->cardUrls($chart));
    }

    public function testATitledShareWhosePictureIsAlreadyAMediumMovesItFirstInsteadOfAddingItAgain(): void
    {
        $chart = $this->titledShare(self::CHART);
        $chart->setMedia([
            new EntryMedium('https://cdn.example/thumb.jpg', 'image'),
            new EntryMedium(self::CHART, 'image', 800, 600),
        ], []);

        $this->writeFlushed($chart, new EntryCardsModel([], [self::picture(self::CHART)]));

        self::assertEquals(
            [
                new EntryMedium(self::CHART, 'image', 800, 600),
                new EntryMedium('https://cdn.example/thumb.jpg', 'image'),
            ],
            $chart->getMedia(),
        );
    }

    public function testAPostSharingAPictureStaysALinkShare(): void
    {
        $post = $this->post('<p>' . self::CHART . '</p>');
        $post->setShareKind(ShareKind::Link);

        $this->writeFlushed($post, new EntryCardsModel([], [self::picture(self::CHART)]));

        self::assertSame(ShareKind::Link, $post->getShareKind());
        self::assertSame([], $post->getMedia());
    }

    private function writeFlushed(Entry $entry, EntryCardsModel $cards): void
    {
        $this->writer->write($entry, $cards);
        $this->entityManager->flush();
        $this->entityManager->refresh($entry);
    }

    private static function card(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Card for ' . $url, null, null, 'example.com');
    }

    private static function pageCard(string $url): PageCardModel
    {
        return new PageCardModel(self::card($url), null);
    }

    private static function picture(string $url): PageCardModel
    {
        return new PageCardModel(null, new EntryMedium($url, 'image'));
    }

    private function post(?string $contentHtml, ?string $url = null): Entry
    {
        $now = new \DateTimeImmutable('2026-10-11 12:00:00');
        $guid = 'https://mastodon.example/@someone/' . ++$this->posts;
        $entry = new Entry($this->feed, $guid, $url, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->setHeadline(Headline::derived('Post'));
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function titledShare(string $target): Entry
    {
        $entry = $this->post(null, $target);
        $entry->setHeadline(Headline::fromFeed('A story'));
        $entry->setShareKind(ShareKind::Link);

        return $entry;
    }

    /** @return list<string> */
    private function cardUrls(Entry $entry): array
    {
        $cardsByEntryId = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return array_map(
            static fn (LinkCardContent $card): string => $card->url,
            $cardsByEntryId[$entry->requireId()] ?? [],
        );
    }

    /** @return list<mixed> */
    private function positions(Entry $entry): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT position FROM link_card WHERE entry_id = ? ORDER BY position',
            [$entry->requireId()],
        );
    }
}
