<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Opml\OpmlExporter;
use App\Service\Social\AuthorFeeds;
use App\Service\Social\Model\HiddenProfileModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class OpmlExporterTest extends DbTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private function exporter(): OpmlExporter
    {
        $exporter = self::getContainer()->get(OpmlExporter::class);
        self::assertInstanceOf(OpmlExporter::class, $exporter);

        return $exporter;
    }

    public function testExportsTaggedAndUntaggedFeeds(): void
    {
        $user = new User('x@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);

        $tagged = new Feed('https://news.example.com/feed.xml');
        $tagged->setTitle('News & Views');
        $tagged->setSiteUrl('https://news.example.com/');
        $this->entityManager->persist($tagged);
        $untagged = new Feed('https://blog.example.com/feed.xml');
        $untagged->setTitle('Blog');
        $this->entityManager->persist($untagged);

        $tag = new Tag($user, 'Daily');
        $this->entityManager->persist($tag);

        $taggedSubscription = new Subscription($user, $tagged, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $taggedSubscription->addTag($tag);
        $this->entityManager->persist($taggedSubscription);
        $untaggedSubscription = new Subscription($user, $untagged, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($untaggedSubscription);
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('<opml version="2.0">', $xml);
        // Tag group wraps its feed; XML-escaped ampersand survives.
        self::assertStringContainsString('text="Daily"', $xml);
        self::assertStringContainsString('xmlUrl="https://news.example.com/feed.xml"', $xml);
        self::assertStringContainsString('News &amp; Views', $xml);
        self::assertStringContainsString('xmlUrl="https://blog.example.com/feed.xml"', $xml);

        // It must be well-formed and re-parseable.
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
    }

    public function testTheHeadTitlesTheExportAndTheMarkupIsIndented(): void
    {
        $user = new User('head@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString(
            "<opml version=\"2.0\">\n  <head>\n    <title>Simple Feed Reader subscriptions</title>\n  </head>",
            $xml,
        );
    }

    public function testACustomSubscriptionTitleWinsOverTheFeedTitle(): void
    {
        $user = new User('custom@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://custom.example.com/feed.xml');
        $feed->setTitle('Feed title');
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->setCustomTitle('My title');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('text="My title" title="My title"', $xml);
        self::assertStringNotContainsString('Feed title', $xml);
    }

    public function testAPrivateSocialFeedIsLeftOut(): void
    {
        $user = new User('opml-social@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $now = new \DateTimeImmutable('2026-10-12T00:00:00Z');
        $rss = new Feed('https://blog.example.com/feed.xml');
        $private = new Feed('social:bluesky:1:home');
        $private->setSourceFormat(SourceFormat::BLUESKY_API);
        foreach ([$rss, $private] as $feed) {
            $this->entityManager->persist($feed);
            $this->entityManager->persist(new Subscription($user, $feed, $now));
        }
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('xmlUrl="https://blog.example.com/feed.xml"', $xml);
        self::assertStringNotContainsString('social:', $xml);
    }

    public function testAnAuthorFeedIsExportedAsItsProfilesPublicRssWithItsTagAndTitle(): void
    {
        $user = new User('opml-author@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $tag = new Tag($user, 'People');
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
        $authorFeeds = self::getContainer()->get(AuthorFeeds::class);
        self::assertInstanceOf(AuthorFeeds::class, $authorFeeds);
        $authorFeeds->create($account, new HiddenProfileModel(self::RUSTY, 'Rusty'), [$tag]);

        $xml = $this->exporter()->export($user);

        self::assertMatchesRegularExpression(
            '#<outline text="People" title="People">\s*<outline type="rss" text="Rusty" title="Rusty" xmlUrl="'
            . preg_quote('https://bsky.app/profile/' . self::RUSTY . '/rss', '#') . '"#',
            $xml,
        );
        self::assertStringNotContainsString('social:', $xml);
    }

    public function testAnAuthorFeedAndTheRefusedRssFeedItReplacesExportAsOneOutline(): void
    {
        $user = new User('opml-author-twice@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $rss = new Feed('https://bsky.app/profile/' . self::RUSTY . '/rss');
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($user, $rss, new \DateTimeImmutable('2026-10-12T00:00:00Z')));
        $this->entityManager->flush();
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
        $authorFeeds = self::getContainer()->get(AuthorFeeds::class);
        self::assertInstanceOf(AuthorFeeds::class, $authorFeeds);
        $authorFeeds->create($account, new HiddenProfileModel(self::RUSTY, 'Rusty'), []);

        self::assertSame(1, substr_count($this->exporter()->export($user), 'type="rss"'));
    }
}
