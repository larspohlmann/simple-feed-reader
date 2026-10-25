<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Opml\OpmlExporter;
use App\Service\Social\Model\PrivateFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class OpmlExporterTest extends DbTestCase
{
    private function exporter(): OpmlExporter
    {
        $exporter = self::getContainer()->get(OpmlExporter::class);
        self::assertInstanceOf(OpmlExporter::class, $exporter);

        return $exporter;
    }

    public function testAChildTagNestsInsideItsParentsOutline(): void
    {
        $user = new User('opml-nested@example.com', new \DateTimeImmutable('2026-10-21T00:00:00Z'));
        $this->entityManager->persist($user);
        [$reading, $longReads] = $this->parentAndChild($user);
        $this->taggedSubscription($user, 'https://parent.example.com/feed.xml', $reading);
        $this->taggedSubscription($user, 'https://child.example.com/feed.xml', $longReads);
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertMatchesRegularExpression(
            '#<outline text="Reading" title="Reading">\s*'
            . '<outline type="rss" text="[^"]*" title="[^"]*" xmlUrl="https://parent.example.com/feed.xml"/>\s*'
            . '<outline text="Long reads" title="Long reads">\s*'
            . '<outline type="rss" text="[^"]*" title="[^"]*" xmlUrl="https://child.example.com/feed.xml"/>\s*'
            . '</outline>\s*</outline>#',
            $xml,
        );
    }

    public function testAParentWithoutFeedsOfItsOwnStillWrapsItsChild(): void
    {
        $user = new User('opml-empty-parent@example.com', new \DateTimeImmutable('2026-10-21T00:00:00Z'));
        $this->entityManager->persist($user);
        [, $longReads] = $this->parentAndChild($user);
        $this->taggedSubscription($user, 'https://child.example.com/feed.xml', $longReads);
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertMatchesRegularExpression(
            '#<outline text="Reading" title="Reading">\s*<outline text="Long reads" title="Long reads">#',
            $xml,
        );
    }

    public function testSiblingTagsExportByPositionThenName(): void
    {
        $user = new User('opml-sibling-order@example.com', new \DateTimeImmutable('2026-10-21T00:00:00Z'));
        $this->entityManager->persist($user);
        $alpha = $this->tagAt($user, 'Alpha', 1);
        $gamma = $this->tagAt($user, 'Gamma', 0);
        $beta = $this->tagAt($user, 'Beta', 0);
        $essays = $this->tagAt($user, 'Essays', 1, $beta);
        $reviews = $this->tagAt($user, 'Reviews', 0, $beta);
        $longReads = $this->tagAt($user, 'Long reads', 0, $beta);
        foreach ([$alpha, $gamma, $essays, $reviews, $longReads] as $index => $tag) {
            $this->taggedSubscription($user, sprintf('https://feed-%d.example.com/feed.xml', $index), $tag);
        }
        $this->entityManager->flush();

        $outlines = new \DOMXPath($this->exportedDocument($user));

        self::assertSame(['Beta', 'Gamma', 'Alpha'], $this->texts($outlines, '/opml/body/outline[not(@type)]'));
        self::assertSame(
            ['Long reads', 'Reviews', 'Essays'],
            $this->texts($outlines, '/opml/body/outline[@text="Beta"]/outline[not(@type)]'),
        );
    }

    public function testAnUntaggedFeedListedBeforeATaggedOneLeavesBothInTheExport(): void
    {
        $user = new User('opml-untagged-first@example.com', new \DateTimeImmutable('2026-10-21T00:00:00Z'));
        $this->entityManager->persist($user);
        $untagged = new Feed('https://untagged.example.com/feed.xml');
        $this->entityManager->persist($untagged);
        $this->entityManager->persist(
            new Subscription($user, $untagged, new \DateTimeImmutable('2026-10-21T00:00:00Z')),
        );
        $this->taggedSubscription($user, 'https://tagged.example.com/feed.xml', $this->tagAt($user, 'Daily', 0));
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('xmlUrl="https://untagged.example.com/feed.xml"', $xml);
        self::assertStringContainsString('xmlUrl="https://tagged.example.com/feed.xml"', $xml);
    }

    public function testAGrandchildTagNestsTwoOutlinesDeepThoughItsAncestorsHaveNoFeeds(): void
    {
        $user = new User('opml-grandchild@example.com', new \DateTimeImmutable('2026-10-25T00:00:00Z'));
        $this->entityManager->persist($user);
        $reading = $this->tagAt($user, 'Reading', 0);
        $longReads = $this->tagAt($user, 'Long reads', 1, $reading);
        $essays = $this->tagAt($user, 'Essays', 2, $longReads);
        $this->taggedSubscription($user, 'https://essay.example.com/feed.xml', $essays);
        $this->entityManager->flush();

        self::assertMatchesRegularExpression(
            '#<outline text="Reading" title="Reading">\s*'
            . '<outline text="Long reads" title="Long reads">\s*'
            . '<outline text="Essays" title="Essays">\s*'
            . '<outline type="rss" text="[^"]*" title="[^"]*" xmlUrl="https://essay.example.com/feed.xml"/>#',
            $this->exporter()->export($user),
        );
    }

    private function tagAt(User $user, string $name, int $position, ?Tag $parent = null): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $tag->setParent($parent);
        $this->entityManager->persist($tag);

        return $tag;
    }

    private function exportedDocument(User $user): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($this->exporter()->export($user)));

        return $document;
    }

    /** @return list<string> */
    private function texts(\DOMXPath $outlines, string $query): array
    {
        $texts = [];
        foreach ($outlines->query($query) ?: [] as $outline) {
            self::assertInstanceOf(\DOMElement::class, $outline);
            $texts[] = $outline->getAttribute('text');
        }

        return $texts;
    }

    /** @return array{0: Tag, 1: Tag} */
    private function parentAndChild(User $user): array
    {
        $reading = new Tag($user, 'Reading');
        $reading->setPosition(0);
        $this->entityManager->persist($reading);
        $longReads = new Tag($user, 'Long reads');
        $longReads->setPosition(1);
        $longReads->setParent($reading);
        $this->entityManager->persist($longReads);

        return [$reading, $longReads];
    }

    private function taggedSubscription(User $user, string $url, Tag $tag): void
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-21T00:00:00Z'));
        $subscription->addTag($tag);
        $this->entityManager->persist($subscription);
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
        $privateFeeds = SocialFeeds::privateFeeds(self::getContainer());
        $privateFeeds->subscribe($account, PrivateFeedModel::author(BlueskyProfiles::RUSTY, 'Rusty'), [$tag]);

        $xml = $this->exporter()->export($user);

        self::assertMatchesRegularExpression(
            '#<outline text="People" title="People">\s*<outline type="rss" text="Rusty" title="Rusty" xmlUrl="'
            . preg_quote('https://bsky.app/profile/' . BlueskyProfiles::RUSTY . '/rss', '#') . '"#',
            $xml,
        );
        self::assertStringNotContainsString('social:', $xml);
    }

    public function testAnAuthorFeedAndTheRefusedRssFeedItReplacesExportAsOneOutline(): void
    {
        $user = new User('opml-author-twice@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $rss = new Feed('https://bsky.app/profile/' . BlueskyProfiles::RUSTY . '/rss');
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($user, $rss, new \DateTimeImmutable('2026-10-12T00:00:00Z')));
        $this->entityManager->flush();
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
        $privateFeeds = SocialFeeds::privateFeeds(self::getContainer());
        $privateFeeds->subscribe($account, PrivateFeedModel::author(BlueskyProfiles::RUSTY, 'Rusty'), []);

        self::assertSame(1, substr_count($this->exporter()->export($user), 'type="rss"'));
    }
}
