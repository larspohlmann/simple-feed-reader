<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlImporter;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;

final class OpmlImporterTest extends DbTestCase
{
    private function importer(): OpmlImporter
    {
        $svc = self::getContainer()->get(OpmlImporter::class);
        self::assertInstanceOf(OpmlImporter::class, $svc);

        return $svc;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/opml/subscriptions.opml');
    }

    /** Wraps the given <body> inner markup in a minimal OPML 2.0 document. */
    private function opml(string $bodyInner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<opml version="2.0"><head><title>t</title></head><body>'
            . $bodyInner
            . '</body></opml>';
    }

    /** @return list<Subscription> */
    private function subsOf(User $user): array
    {
        return $this->em->getRepository(Subscription::class)->findBy(['user' => $user]);
    }

    /** @return list<Feed> */
    private function feedsWithUrl(string $url): array
    {
        return $this->em->getRepository(Feed::class)->findBy(['url' => $url]);
    }

    public function testImportsFeedsCreatesTagsAndSchedulesFetch(): void
    {
        $user = $this->user('import@example.com');

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(3, $result->imported); // 2 under News + 1 root feed
        self::assertSame(0, $result->alreadySubscribed);

        $subs = $this->em->getRepository(Subscription::class)->findBy(['user' => $user]);
        self::assertCount(3, $subs);

        // New feeds are due now so the next refresh populates them (no inline fetch).
        $feed = $this->em->getRepository(Feed::class)->findOneBy(['url' => 'https://blog.example.com/feed.xml']);
        self::assertNotNull($feed);
        self::assertNotNull($feed->getNextFetchAt());

        // The "News" folder became a tag attached to its two feeds.
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'News']);
        self::assertNotNull($tag);
    }

    public function testReusesExistingFeedAndSkipsAlreadySubscribed(): void
    {
        $user = $this->user('dupe@example.com');
        $feed = new Feed('https://blog.example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(2, $result->imported);        // the two News feeds
        self::assertSame(1, $result->alreadySubscribed); // blog.example.com
        $rows = $this->em->getRepository(Feed::class)->findBy(['url' => 'https://blog.example.com/feed.xml']);
        self::assertCount(1, $rows);
    }

    public function testRejectsNonOpml(): void
    {
        $user = $this->user('bad@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import($user, '<html><body>not opml</body></html>');
    }

    public function testRejectsMalformedXml(): void
    {
        $user = $this->user('malformed@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import($user, 'this is { not xml');
    }

    public function testRejectsDtd(): void
    {
        $user = $this->user('dtd@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import(
            $user,
            '<?xml version="1.0"?><!DOCTYPE opml [<!ENTITY z "zz">]><opml version="2.0"><body/></opml>',
        );
    }

    public function testDuplicateNewUrlSubscribesOnce(): void
    {
        $user = $this->user('dup-new@example.com');
        $url = 'https://dup.example.com/feed.xml';
        $opml = $this->opml(
            '<outline text="A"><outline type="rss" text="F" xmlUrl="' . $url . '"/></outline>'
            . '<outline text="B"><outline type="rss" text="F" xmlUrl="' . $url . '"/></outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed); // the second listing
        self::assertCount(1, $this->subsOf($user));
        self::assertCount(1, $this->feedsWithUrl($url));
    }

    public function testDuplicateExistingUnsubscribedUrlSubscribesOnce(): void
    {
        $user = $this->user('dup-existing@example.com');
        $url = 'https://exists.example.com/feed.xml';
        $this->em->persist(new Feed($url)); // committed, user NOT subscribed
        $this->em->flush();

        $opml = $this->opml(
            '<outline type="rss" text="F" xmlUrl="' . $url . '"/>'
            . '<outline type="rss" text="F" xmlUrl="' . $url . '"/>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertCount(1, $this->subsOf($user));
        self::assertCount(1, $this->feedsWithUrl($url));
    }

    public function testOverlongUrlIsInvalidAndRestStillImports(): void
    {
        $user = $this->user('overlong@example.com');
        $longUrl = 'https://long.example.com/' . str_repeat('a', 800); // > 750 chars
        $goodUrl = 'https://ok.example.com/feed.xml';
        $opml = $this->opml(
            '<outline type="rss" text="Long" xmlUrl="' . $longUrl . '"/>'
            . '<outline type="rss" text="Good" xmlUrl="' . $goodUrl . '"/>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
        self::assertCount(1, $this->subsOf($user));
        self::assertSame([], $this->feedsWithUrl($longUrl)); // no oversized Feed persisted
    }

    public function testReusesPreExistingTagCaseInsensitively(): void
    {
        $user = $this->user('tag-reuse@example.com');
        $this->em->persist(new Tag($user, 'news')); // lowercase, pre-existing
        $this->em->flush();

        $opml = $this->opml(
            '<outline text="News" title="News">'
            . '<outline type="rss" text="F" xmlUrl="https://n.example.com/feed.xml"/>'
            . '</outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        $tags = $this->em->getRepository(Tag::class)->findBy(['user' => $user]);
        self::assertCount(1, $tags); // reused, not duplicated
        self::assertSame('news', $tags[0]->getName()); // original casing preserved

        $subs = $this->subsOf($user);
        self::assertCount(1, $subs);
        self::assertCount(1, $subs[0]->getTags());
    }

    public function testSkippedOverLimitCreatesNoOrphanFeeds(): void
    {
        $user = $this->user('atcap@example.com');
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($i = 0; $i < SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER; $i++) {
            $feed = new Feed(sprintf('https://seed%d.example.com/feed.xml', $i));
            $this->em->persist($feed);
            $this->em->persist(new Subscription($user, $feed, $when));
        }
        $this->em->flush();

        $feedsBefore = (int) $this->em->getRepository(Feed::class)
            ->createQueryBuilder('f')->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(0, $result->imported);
        self::assertSame(3, $result->skippedOverLimit); // the 3 importable fixture feeds

        $feedsAfter = (int) $this->em->getRepository(Feed::class)
            ->createQueryBuilder('f')->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($feedsBefore, $feedsAfter); // no orphan Feed rows created
    }
}
