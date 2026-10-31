<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Opml\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlImporter;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;

final class OpmlImporterTest extends DbTestCase
{
    use SeedsUsers;

    private function importer(): OpmlImporter
    {
        $importer = self::getContainer()->get(OpmlImporter::class);
        self::assertInstanceOf(OpmlImporter::class, $importer);

        return $importer;
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
    private function subscriptionsOf(User $user): array
    {
        return $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $user]);
    }

    /** @return list<Feed> */
    private function feedsWithUrl(string $url): array
    {
        return $this->entityManager->getRepository(Feed::class)->findBy(['url' => $url]);
    }

    public function testImportsFeedsCreatesTagsAndSchedulesFetch(): void
    {
        $user = $this->user('import@example.com');

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(3, $result->imported); // 2 under News + 1 root feed
        self::assertSame(0, $result->alreadySubscribed);

        $subscriptions = $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $user]);
        self::assertCount(3, $subscriptions);

        // New feeds are due now so the next refresh populates them (no inline fetch).
        $feed = $this->entityManager
            ->getRepository(Feed::class)
            ->findOneBy(['url' => 'https://blog.example.com/feed.xml']);
        self::assertNotNull($feed);
        self::assertNotNull($feed->getNextFetchAt());

        // The "News" folder became a tag attached to its two feeds.
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'News']);
        self::assertNotNull($tag);
    }

    public function testAnImportHoldsTheTagStructureLockWhileItNestsTags(): void
    {
        $this->skipWithoutRowLocks();
        $user = $this->user('import-locks@example.com');
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->importer()->import($user, $this->fixture());

        self::assertCount(1, $recorder->queriesMatching('for update'));
    }

    public function testReusesExistingFeedAndSkipsAlreadySubscribed(): void
    {
        $user = $this->user('dupe@example.com');
        $feed = new Feed('https://blog.example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(2, $result->imported);        // the two News feeds
        self::assertSame(1, $result->alreadySubscribed); // blog.example.com
        $rows = $this->entityManager
            ->getRepository(Feed::class)
            ->findBy(['url' => 'https://blog.example.com/feed.xml']);
        self::assertCount(1, $rows);
    }

    public function testRejectsNonOpml(): void
    {
        $user = $this->user('bad@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import($user, /** @lang TEXT */ '<html><body>not opml</body></html>');
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
        self::assertCount(1, $this->subscriptionsOf($user));
        self::assertCount(1, $this->feedsWithUrl($url));
    }

    public function testDuplicateExistingUnsubscribedUrlSubscribesOnce(): void
    {
        $user = $this->user('dup-existing@example.com');
        $url = 'https://exists.example.com/feed.xml';
        $this->entityManager->persist(new Feed($url)); // committed, user NOT subscribed
        $this->entityManager->flush();

        $opml = $this->opml(
            '<outline type="rss" text="F" xmlUrl="' . $url . '"/>'
            . '<outline type="rss" text="F" xmlUrl="' . $url . '"/>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertCount(1, $this->subscriptionsOf($user));
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
        self::assertCount(1, $this->subscriptionsOf($user));
        self::assertSame([], $this->feedsWithUrl($longUrl)); // no oversized Feed persisted
    }

    public function testReusesPreExistingTagCaseInsensitively(): void
    {
        $user = $this->user('tag-reuse@example.com');
        $this->entityManager->persist(new Tag($user, 'news')); // lowercase, pre-existing
        $this->entityManager->flush();

        $opml = $this->opml(
            '<outline text="News" title="News">'
            . '<outline type="rss" text="F" xmlUrl="https://n.example.com/feed.xml"/>'
            . '</outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        $tags = $this->entityManager->getRepository(Tag::class)->findBy(['user' => $user]);
        self::assertCount(1, $tags); // reused, not duplicated
        self::assertSame('news', $tags[0]->getName()); // original casing preserved

        $subscriptions = $this->subscriptionsOf($user);
        self::assertCount(1, $subscriptions);
        self::assertCount(1, $subscriptions[0]->getTags());
    }

    public function testSkippedOverLimitCreatesNoOrphanFeeds(): void
    {
        $user = $this->user('atcap@example.com');
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($index = 0; $index < SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER; $index++) {
            $feed = new Feed(sprintf('https://seed%d.example.com/feed.xml', $index));
            $this->entityManager->persist($feed);
            $this->entityManager->persist(new Subscription($user, $feed, $when));
        }
        $this->entityManager->flush();

        $feedsBefore = (int) $this->entityManager->getRepository(Feed::class)
            ->createQueryBuilder('f')->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(0, $result->imported);
        self::assertSame(3, $result->skippedOverLimit); // the 3 importable fixture feeds

        $feedsAfter = (int) $this->entityManager->getRepository(Feed::class)
            ->createQueryBuilder('f')->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($feedsBefore, $feedsAfter); // no orphan Feed rows created
    }

    public function testAnEmptyBodyIsRefusedBeforeParsing(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('The OPML body is empty or larger than 1 MB.');

        $this->importer()->import($this->user('empty-opml@example.com'), '');
    }

    public function testABodyOverOneMegabyteIsRefusedBeforeParsing(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('The OPML body is empty or larger than 1 MB.');

        $this->importer()->import($this->user('large-opml@example.com'), str_repeat('a', 1_048_577));
    }

    public function testABodyOfExactlyOneMegabyteReachesTheParser(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('Not a well-formed OPML 2.0 document.');

        $this->importer()->import($this->user('limit-opml@example.com'), str_repeat('a', 1_048_576));
    }

    public function testANestedGroupBecomesAChildOfTheTopLevelGroup(): void
    {
        $user = $this->user('opml-nested@example.com');
        $opml = $this->opml(
            '<outline text="Reading"><outline text="Long reads">'
            . '<outline type="rss" text="F" xmlUrl="https://nested.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        self::assertSame('Reading', $this->tagNamed($user, 'Long reads')->getParent()?->getName());
        self::assertNull($this->tagNamed($user, 'Reading')->getParent());
        self::assertSame(['Long reads'], $this->tagNamesOf($this->subscriptionsOf($user)[0]));
    }

    public function testAThreeLevelFileImportsEveryLevel(): void
    {
        $user = $this->user('opml-deep@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline text="B"><outline text="C">'
            . '<outline type="rss" text="F" xmlUrl="https://deep.example.com/feed.xml"/>'
            . '</outline></outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
        self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
        self::assertSame(['C'], $this->tagNamesOf($this->subscriptionsOf($user)[0]));
    }

    public function testADeeperFileKeepsItsOutermostThreeLevels(): void
    {
        $user = $this->user('opml-clamped@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline text="B"><outline text="C"><outline text="D">'
            . '<outline type="rss" text="F" xmlUrl="https://clamped.example.com/feed.xml"/>'
            . '</outline></outline></outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
        self::assertSame(['C'], $this->tagNamesOf($this->subscriptionsOf($user)[0]));
        self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'D']));
    }

    public function testReimportingIntoExistingTagsCreatesNoneAndKeepsTheTree(): void
    {
        $user = $this->user('opml-reimport@example.com');
        $opml = fn (string $host): string => $this->opml(
            '<outline text="A"><outline text="B"><outline text="C">'
            . sprintf('<outline type="rss" text="F" xmlUrl="https://%s.example.com/feed.xml"/>', $host)
            . '</outline></outline></outline>',
        );
        $this->importer()->import($user, $opml('first-import'));

        $result = $this->importer()->import($user, $opml('second-import'));

        self::assertSame(1, $result->imported);
        self::assertCount(3, $this->entityManager->getRepository(Tag::class)->findBy(['user' => $user]));
        self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
        self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
        self::assertSame(['C'], $this->tagNamesOf($this->subscriptionsOf($user)[1]));
    }

    public function testThePathGoesOnPastAParentThatAgreesWithTheFile(): void
    {
        $user = $this->user('opml-agreeing@example.com');
        $tagB = $this->storedTag($user, 'B', null);
        $this->storedTag($user, 'C', $tagB);
        $opml = $this->opml(
            '<outline text="A"><outline text="B"><outline text="C">'
            . '<outline type="rss" text="F" xmlUrl="https://agreeing.example.com/feed.xml"/>'
            . '</outline></outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
        self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
    }

    public function testATagWhoseSubtreeIsFullKeepsItsPlace(): void
    {
        $user = $this->user('opml-conflict@example.com');
        $longReads = $this->storedTag($user, 'Long reads', null);
        $this->storedTag($user, 'Deep', $this->storedTag($user, 'Essays', $longReads));
        $opml = $this->opml(
            '<outline text="Reading"><outline text="Long reads">'
            . '<outline type="rss" text="F" xmlUrl="https://conflict.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->imported);
        self::assertNull($this->tagNamed($user, 'Long reads')->getParent());
        self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy([
            'user' => $user,
            'name' => 'Reading',
        ]));
    }

    public function testATagNestedEarlierInTheFileTakesAChildLater(): void
    {
        $user = $this->user('opml-child-as-parent@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline text="B">'
            . '<outline type="rss" text="F1" xmlUrl="https://child-parent-1.example.com/feed.xml"/>'
            . '</outline></outline>'
            . '<outline text="B"><outline text="C">'
            . '<outline type="rss" text="F2" xmlUrl="https://child-parent-2.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(2, $result->imported);
        self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
        self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
    }

    public function testATagWithAFullSubtreeEarlierInTheFileIsNotNestedLater(): void
    {
        $user = $this->user('opml-parent-as-child@example.com');
        $opml = $this->opml(
            '<outline text="X"><outline text="Y"><outline text="Z">'
            . '<outline type="rss" text="F1" xmlUrl="https://parent-child-1.example.com/feed.xml"/>'
            . '</outline></outline></outline>'
            . '<outline text="W"><outline text="X">'
            . '<outline type="rss" text="F2" xmlUrl="https://parent-child-2.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(2, $result->imported);
        self::assertNull($this->tagNamed($user, 'X')->getParent());
        self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'W']));
    }

    public function testAFileNestsUnderAnExistingChildTag(): void
    {
        $user = $this->user('opml-under-child@example.com');
        $this->storedTag($user, 'Web', $this->storedTag($user, 'Tech', null));
        $opml = $this->opml(
            '<outline text="Web"><outline text="Frontend">'
            . '<outline type="rss" text="F" xmlUrl="https://frontend.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('Web', $this->tagNamed($user, 'Frontend')->getParent()?->getName());
        self::assertSame('Tech', $this->tagNamed($user, 'Web')->getParent()?->getName());
    }

    public function testATagAlreadyNestedKeepsItsFirstParent(): void
    {
        $user = $this->user('opml-renested@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline text="B">'
            . '<outline type="rss" text="F1" xmlUrl="https://renest-1.example.com/feed.xml"/>'
            . '</outline></outline>'
            . '<outline text="C"><outline text="B">'
            . '<outline type="rss" text="F2" xmlUrl="https://renest-2.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
        self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy([
            'user' => $user,
            'name' => 'C',
        ]));
    }

    public function testANamelessGroupPassesItsPathThrough(): void
    {
        $user = $this->user('opml-nameless@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline><outline text="B">'
            . '<outline type="rss" text="F" xmlUrl="https://nameless.example.com/feed.xml"/>'
            . '</outline></outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
        self::assertSame(['B'], $this->tagNamesOf($this->subscriptionsOf($user)[0]));
    }

    public function testAGroupNameIsTrimmedAndABlankTextFallsBackToTheTitle(): void
    {
        $user = $this->user('opml-trim@example.com');
        $opml = $this->opml(
            '<outline text="  Reading  "><outline text="   " title=" Long reads ">'
            . '<outline type="rss" text="F" xmlUrl="https://trim.example.com/feed.xml"/>'
            . '</outline></outline>',
        );

        $this->importer()->import($user, $opml);

        self::assertSame('Reading', $this->tagNamed($user, 'Long reads')->getParent()?->getName());
    }

    public function testADeclaredVerbatimFormatImportsAsThatFormat(): void
    {
        $user = $this->user('wpjson@example.com');
        $url = 'https://blog.example.com/wp-json/wp/v2/posts';

        $this->importer()->import(
            $user,
            $this->opml('<outline type="rss" text="Blog" xmlUrl="' . $url . '" sourceFormat="wp-json"/>'),
        );

        self::assertSame(SourceFormat::WP_JSON, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }

    public function testADeclaredFormatThatIsNotVerbatimImportsAsXml(): void
    {
        $user = $this->user('social@example.com');
        $url = 'https://social.example.com/feed';

        $this->importer()->import(
            $user,
            $this->opml('<outline type="rss" text="S" xmlUrl="' . $url . '" sourceFormat="bluesky-api"/>'),
        );

        self::assertSame(SourceFormat::XML, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }

    public function testACloudcastsUrlWithoutAFormatImportsAsMixcloud(): void
    {
        $user = $this->user('mixcloud@example.com');
        $url = 'https://api.mixcloud.com/johndigweed/cloudcasts/';

        $this->importer()->import($user, $this->opml('<outline type="rss" text="JD" xmlUrl="' . $url . '"/>'));

        self::assertSame(SourceFormat::MIXCLOUD_API, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }

    private function storedTag(User $user, string $name, ?Tag $parent): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setParent($parent);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function tagNamed(User $user, string $name): Tag
    {
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => $name]);
        self::assertInstanceOf(Tag::class, $tag);

        return $tag;
    }

    /** @return list<string> */
    private function tagNamesOf(Subscription $subscription): array
    {
        return array_values(array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $subscription->getTags()->toArray(),
        ));
    }
}
