<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Enum\FeedLanguage;
use App\Service\Content\LinkCard\EntryLinks;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;

final class EntryLinksTest extends DbTestCase
{
    public function testCardsAreAttachedInPostOrderThroughOneRowPerUrl(): void
    {
        $entry = $this->entry('cards');
        $links = $this->links();

        $links->attach($entry, [self::card('https://example.com/b'), self::card('https://example.com/a')]);
        $links->attach($this->entry('again'), [self::card('https://example.com/a?utm_source=x')]);
        $this->entityManager->flush();

        $cards = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);
        self::assertSame(
            ['https://example.com/b', 'https://example.com/a'],
            array_column($cards[$entry->requireId()], 'url'),
        );
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM shared_link'));
    }

    public function testAStatedLanguageReachesANewRow(): void
    {
        $this->links()->attach(
            $this->entry('stated'),
            [self::card('https://example.com/fr')],
            ['https://example.com/fr' => FeedLanguage::French],
        );

        self::assertSame('fr', $this->entityManager->getConnection()->fetchOne('SELECT language FROM shared_link'));
    }

    public function testNoCardsWritesNothing(): void
    {
        $this->links()->attach($this->entry('none'), []);
        $this->entityManager->flush();

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM link_card'));
    }

    public function testAThreadPostCardIsAttachedToItsPost(): void
    {
        $entry = $this->entry('thread');
        $post = new ThreadPost($entry, 1, new ThreadPostContent(null, null, '<p>reply</p>', []));
        $this->entityManager->persist($post);

        $this->links()->attachToThreadPost($post, self::card('https://example.com/reply'));
        $this->entityManager->flush();

        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM link_card WHERE thread_post_id IS NOT NULL'));
    }

    public function testAQuoteKeepsItsCardThroughItsLink(): void
    {
        $entry = $this->entry('quote');
        $card = self::card('https://example.com/quoted');

        $this->links()->quote($entry, self::quote()->withLinkCard($card));

        self::assertEquals($card, $entry->getQuotedPost()?->linkCard);
    }

    public function testAQuoteWithoutACardHasNoLink(): void
    {
        $entry = $this->entry('bare-quote');

        $this->links()->quote($entry, self::quote());

        self::assertNotNull($entry->getQuotedPost());
        self::assertNull($entry->getQuotedPost()->linkCard);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM shared_link'));
    }

    private static function quote(): QuotedPostContent
    {
        return new QuotedPostContent('https://q.example/1', 'Q', 'q.example', null, null, 'text', [], null);
    }

    private static function card(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Card for ' . $url, null, null, 'example.com');
    }

    private function rowCount(string $sql): int
    {
        $count = $this->entityManager->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function entry(string $guid): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '.rss');
        $entry = new Entry(
            $feed,
            $guid,
            null,
            'Post',
            new \DateTimeImmutable('2026-11-15 10:00:00'),
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function links(): EntryLinks
    {
        return PostEnrichers::entryLinks($this->entityManager);
    }
}
