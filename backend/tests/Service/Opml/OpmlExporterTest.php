<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Opml\OpmlExporter;
use App\Tests\DbTestCase;

final class OpmlExporterTest extends DbTestCase
{
    private function exporter(): OpmlExporter
    {
        $svc = self::getContainer()->get(OpmlExporter::class);
        self::assertInstanceOf(OpmlExporter::class, $svc);

        return $svc;
    }

    public function testExportsTaggedAndUntaggedFeeds(): void
    {
        $user = new User('x@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);

        $tagged = new Feed('https://news.example.com/feed.xml');
        $tagged->setTitle('News & Views');
        $tagged->setSiteUrl('https://news.example.com/');
        $this->em->persist($tagged);
        $untagged = new Feed('https://blog.example.com/feed.xml');
        $untagged->setTitle('Blog');
        $this->em->persist($untagged);

        $tag = new Tag($user, 'Daily');
        $this->em->persist($tag);

        $s1 = new Subscription($user, $tagged, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $s1->addTag($tag);
        $this->em->persist($s1);
        $s2 = new Subscription($user, $untagged, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($s2);
        $this->em->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('<opml version="2.0">', $xml);
        // Tag group wraps its feed; XML-escaped ampersand survives.
        self::assertStringContainsString('text="Daily"', $xml);
        self::assertStringContainsString('xmlUrl="https://news.example.com/feed.xml"', $xml);
        self::assertStringContainsString('News &amp; Views', $xml);
        self::assertStringContainsString('xmlUrl="https://blog.example.com/feed.xml"', $xml);

        // It must be well-formed and re-parseable.
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
    }
}
