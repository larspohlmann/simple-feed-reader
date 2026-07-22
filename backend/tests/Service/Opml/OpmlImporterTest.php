<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlImporter;
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
}
