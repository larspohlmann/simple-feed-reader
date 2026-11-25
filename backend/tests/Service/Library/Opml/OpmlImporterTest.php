<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Library\Opml\Exception\ConflictingTagChoiceException;
use App\Service\Library\Opml\Model\OpmlImportSelectionModel;
use App\Service\Library\Opml\Model\OpmlPlacementChoiceModel;
use App\Service\Library\Opml\OpmlImporter;
use App\Service\Library\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

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
        return (string) file_get_contents(__DIR__ . '/../../../Fixtures/opml/subscriptions.opml');
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

        $result = $this->importer()->import($user, $this->everything($this->fixture()));

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

        $this->importer()->import($user, $this->everything($this->fixture()));

        self::assertCount(1, $recorder->queriesMatching('for update'));
    }

    public function testReusesExistingFeedAndSkipsAlreadySubscribed(): void
    {
        $user = $this->user('dupe@example.com');
        $feed = new Feed('https://blog.example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        $result = $this->importer()->import($user, $this->everything($this->fixture()));

        self::assertSame(2, $result->imported);        // the two News feeds
        self::assertSame(1, $result->alreadySubscribed); // blog.example.com
        $rows = $this->entityManager
            ->getRepository(Feed::class)
            ->findBy(['url' => 'https://blog.example.com/feed.xml']);
        self::assertCount(1, $rows);
    }

    public function testDuplicateNewUrlSubscribesOnce(): void
    {
        $user = $this->user('dup-new@example.com');
        $url = 'https://dup.example.com/feed.xml';
        $opml = $this->opml(
            '<outline text="A"><outline type="rss" text="F" xmlUrl="' . $url . '"/></outline>'
            . '<outline text="B"><outline type="rss" text="F" xmlUrl="' . $url . '"/></outline>',
        );

        $result = $this->importer()->import($user, $this->everything($opml));

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->alreadySubscribed); // the reader keeps only the first listing
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

        $result = $this->importer()->import($user, $this->everything($opml));

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->alreadySubscribed);
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

        $result = $this->importer()->import($user, $this->everything($opml));

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
        self::assertCount(1, $this->subscriptionsOf($user));
        self::assertSame([], $this->feedsWithUrl($longUrl)); // no oversized Feed persisted
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

        $result = $this->importer()->import($user, $this->everything($this->fixture()));

        self::assertSame(0, $result->imported);
        self::assertSame(3, $result->skippedOverLimit); // the 3 importable fixture feeds

        $feedsAfter = (int) $this->entityManager->getRepository(Feed::class)
            ->createQueryBuilder('f')->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($feedsBefore, $feedsAfter); // no orphan Feed rows created
    }

    public function testAScrapedOutlineIsInvalidEvenWhenScrapingIsOn(): void
    {
        $user = $this->user('scrape@example.com');
        StoredUserSettings::reading($this->entityManager, $user)->setScrapeFallbackEnabled(true);
        $this->entityManager->flush();
        $scrapedUrl = 'https://page.example.com/news';
        $opml = $this->opml(
            '<outline type="rss" text="Page" xmlUrl="' . $scrapedUrl . '" sourceFormat="scraped"/>'
            . '<outline type="rss" text="Good" xmlUrl="https://ok.example.com/feed.xml"/>',
        );

        $result = $this->importer()->import($user, $this->everything($opml));

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
        self::assertSame([], $this->feedsWithUrl($scrapedUrl));
    }

    public function testOnlyTheSelectedFeedsAreImported(): void
    {
        $user = $this->user('opml-selected@example.com');
        $opml = $this->opml(
            '<outline text="A">' . self::feed('https://feed1.example/feed') . self::feed('https://feed2.example/feed')
            . '</outline><outline text="B">' . self::feed('https://feed3.example/feed') . '</outline>',
        );

        $result = $this->importer()->import(
            $user,
            new OpmlImportSelectionModel($opml, new OpmlPlacementChoiceModel(null), ['https://feed1.example/feed'], []),
        );

        self::assertSame(1, $result->imported);
        $subscriptions = $this->subscriptionsOf($user);
        self::assertCount(1, $subscriptions);
        self::assertSame('https://feed1.example/feed', $subscriptions[0]->getFeed()->getUrl());
        self::assertNull($this->findTagNamed($user, 'B'));
    }

    public function testASelectedUrlTheFileDoesNotHoldIsIgnored(): void
    {
        $user = $this->user('opml-elsewhere@example.com');
        $opml = $this->opml(self::feed('https://feed1.example/feed'));

        $result = $this->importer()->import(
            $user,
            new OpmlImportSelectionModel(
                $opml,
                new OpmlPlacementChoiceModel(null),
                ['https://elsewhere.example/feed'],
                [],
            ),
        );

        self::assertSame(0, $result->imported);
        self::assertSame(0, $result->invalid);
        self::assertSame([], $this->feedsWithUrl('https://elsewhere.example/feed'));
    }

    public function testAParentTagHoldsTheFoldersAndTheLooseFeeds(): void
    {
        $user = $this->user('opml-parent@example.com');
        $opml = $this->opml(
            self::feed('https://feed0.example/feed')
            . '<outline text="A">' . self::feed('https://feed1.example/feed') . '</outline>',
        );

        $this->importer()->import($user, $this->everything($opml, 'From Feedly'));

        self::assertSame(['From Feedly'], $this->tagNamesFor($user, 'https://feed0.example/feed'));
        self::assertSame(['A'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertSame('From Feedly', $this->tagNamed($user, 'A')->getParent()?->getName());
    }

    public function testAFolderNamedLikeATagElsewhereBecomesANumberedTagAndTheOldOneStays(): void
    {
        $user = $this->user('opml-numbered@example.com');
        $tech = $this->storedTechHoldingAFeed($user);

        $this->importer()->import($user, $this->everything($this->techFile(), 'From Feedly'));

        self::assertSame(['Tech (1)'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertSame('From Feedly', $this->tagNamed($user, 'Tech (1)')->getParent()?->getName());
        $this->entityManager->refresh($tech);
        self::assertNull($tech->getParent());
        self::assertCount(1, $this->subscriptionsTaggedWith($tech));
    }

    public function testImportingTheSameFileTwiceCreatesNoTagTheSecondTime(): void
    {
        $user = $this->user('opml-twice@example.com');
        $this->storedTechHoldingAFeed($user);
        $this->importer()->import($user, $this->everything($this->techFile(), 'From Feedly'));
        $this->entityManager->remove($this->subscriptionTo($user, 'https://feed1.example/feed'));
        $this->entityManager->flush();

        $result = $this->importer()->import($user, $this->everything($this->techFile(), 'From Feedly'));

        self::assertSame(1, $result->imported);
        self::assertSame(['From Feedly', 'Tech', 'Tech (1)'], $this->collectTagNames($user));
    }

    public function testTheSecondOfTwoSameNamedFoldersKeepsItsNumberWhenTheFirstIsNotSelected(): void
    {
        $user = $this->user('opml-second-tech@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline text="Tech">' . self::feed('https://feed1.example/feed')
            . '</outline></outline><outline text="B"><outline text="Tech">'
            . self::feed('https://feed2.example/feed') . '</outline></outline>',
        );

        $this->importer()->import(
            $user,
            new OpmlImportSelectionModel($opml, new OpmlPlacementChoiceModel(null), ['https://feed2.example/feed'], []),
        );

        self::assertSame(['Tech (1)'], $this->tagNamesFor($user, 'https://feed2.example/feed'));
        self::assertSame('B', $this->tagNamed($user, 'Tech (1)')->getParent()?->getName());
        self::assertNull($this->findTagNamed($user, 'Tech'));
        self::assertNull($this->findTagNamed($user, 'A'));
    }

    public function testChosenTagsReplaceTheFolderAndTheParent(): void
    {
        $user = $this->user('opml-chosen@example.com');
        $mine = $this->storedTag($user, 'Mine', null);
        $also = $this->storedTag($user, 'Also', null);
        $opml = $this->opml('<outline text="A">' . self::feed('https://feed1.example/feed') . '</outline>');

        $this->importer()->import($user, new OpmlImportSelectionModel(
            $opml,
            new OpmlPlacementChoiceModel('From Feedly'),
            ['https://feed1.example/feed'],
            ['https://feed1.example/feed' => [$mine->requireId(), $also->requireId()]],
        ));

        self::assertSame(['Also', 'Mine'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertNull($this->findTagNamed($user, 'A'));
        self::assertNull($this->findTagNamed($user, 'From Feedly'));
    }

    public function testAnEmptyChoiceLeavesTheFeedUntagged(): void
    {
        $user = $this->user('opml-empty-choice@example.com');
        $opml = $this->opml('<outline text="A">' . self::feed('https://feed1.example/feed') . '</outline>');

        $this->importer()->import($user, new OpmlImportSelectionModel(
            $opml,
            new OpmlPlacementChoiceModel(null),
            ['https://feed1.example/feed'],
            ['https://feed1.example/feed' => []],
        ));

        self::assertSame([], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertNull($this->findTagNamed($user, 'A'));
    }

    public function testATagOfAnotherAccountIsNotJoined(): void
    {
        $user = $this->user('opml-own-tags@example.com');
        $foreign = $this->storedTag($this->user('opml-foreign-tags@example.com'), 'Theirs', null);
        $opml = $this->opml(self::feed('https://feed1.example/feed'));

        $this->importer()->import($user, new OpmlImportSelectionModel(
            $opml,
            new OpmlPlacementChoiceModel(null),
            ['https://feed1.example/feed'],
            ['https://feed1.example/feed' => [$foreign->requireId()]],
        ));

        self::assertSame([], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertSame([], $this->subscriptionsTaggedWith($foreign));
    }

    public function testAddedTagsJoinTheFolderTag(): void
    {
        $user = $this->user('opml-added@example.com');
        $later = $this->storedTag($user, 'Later', null);
        $opml = $this->opml('<outline text="Tech">' . self::feed('https://feed1.example/feed') . '</outline>');

        $this->importer()->import($user, new OpmlImportSelectionModel(
            $opml,
            new OpmlPlacementChoiceModel(null),
            ['https://feed1.example/feed'],
            [],
            ['https://feed1.example/feed' => [$later->requireId()]],
        ));

        self::assertSame(['Later', 'Tech'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
    }

    public function testAddedTagsKeepTheParentPath(): void
    {
        $user = $this->user('opml-added-parent@example.com');
        $later = $this->storedTag($user, 'Later', null);
        $opml = $this->opml('<outline text="Tech">' . self::feed('https://feed1.example/feed') . '</outline>');

        $this->importer()->import($user, new OpmlImportSelectionModel(
            $opml,
            new OpmlPlacementChoiceModel('P'),
            ['https://feed1.example/feed'],
            [],
            ['https://feed1.example/feed' => [$later->requireId()]],
        ));

        self::assertSame(['Later', 'Tech'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertSame('P', $this->tagNamed($user, 'Tech')->getParent()?->getName());
    }

    public function testAFeedWithBothChoicesIsRefused(): void
    {
        $user = $this->user('opml-both@example.com');
        $mine = $this->storedTag($user, 'Mine', null);
        $opml = $this->opml('<outline text="Tech">' . self::feed('https://feed1.example/feed') . '</outline>');

        try {
            $this->importer()->import($user, new OpmlImportSelectionModel(
                $opml,
                new OpmlPlacementChoiceModel(null),
                ['https://feed1.example/feed'],
                ['https://feed1.example/feed' => [$mine->requireId()]],
                ['https://feed1.example/feed' => [$mine->requireId()]],
            ));
            self::fail('Expected ConflictingTagChoiceException');
        } catch (ConflictingTagChoiceException $exception) {
            self::assertStringContainsString('https://feed1.example/feed', $exception->getMessage());
        }

        self::assertSame([], $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $user]));
        self::assertNull($this->findTagNamed($user, 'Tech'));
    }

    public function testAnAddedTagOfAnotherAccountIsNotJoined(): void
    {
        $user = $this->user('opml-added-own@example.com');
        $foreign = $this->storedTag($this->user('opml-added-foreign@example.com'), 'Theirs', null);
        $opml = $this->opml('<outline text="Tech">' . self::feed('https://feed1.example/feed') . '</outline>');

        $this->importer()->import($user, new OpmlImportSelectionModel(
            $opml,
            new OpmlPlacementChoiceModel(null),
            ['https://feed1.example/feed'],
            [],
            ['https://feed1.example/feed' => [$foreign->requireId()]],
        ));

        self::assertSame(['Tech'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertSame([], $this->subscriptionsTaggedWith($foreign));
    }

    public function testTheFeedTitleComesFromTheFile(): void
    {
        $user = $this->user('opml-title@example.com');
        $opml = $this->opml('<outline text="Example" xmlUrl="https://new.example/feed.xml"/>');

        $this->importer()->import($user, $this->everything($opml));

        self::assertSame('Example', $this->feedsWithUrl('https://new.example/feed.xml')[0]->getTitle());
    }

    public function testAFolderTooDeepPutsItsFeedInTheFolderAbove(): void
    {
        $user = $this->user('opml-too-deep@example.com');
        $opml = $this->opml(
            '<outline text="A"><outline text="B"><outline text="C"><outline text="D">'
            . self::feed('https://feed1.example/feed') . '</outline></outline></outline></outline>',
        );

        $this->importer()->import($user, $this->everything($opml));

        self::assertSame(['C'], $this->tagNamesFor($user, 'https://feed1.example/feed'));
        self::assertNull($this->findTagNamed($user, 'D'));
    }

    public function testASelectionOverTheCapImportsUpToIt(): void
    {
        $user = $this->user('opml-cap@example.com');
        $user->setMaxSubscriptions(1);
        $this->entityManager->flush();
        $opml = $this->opml(self::feed('https://feed1.example/feed') . self::feed('https://feed2.example/feed'));

        $result = $this->importer()->import($user, $this->everything($opml));

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
        self::assertCount(1, $this->feedsWithUrl('https://feed1.example/feed'));
        self::assertSame([], $this->feedsWithUrl('https://feed2.example/feed'));
    }

    private function everything(string $opml, ?string $parent = null): OpmlImportSelectionModel
    {
        preg_match_all('/xmlUrl="([^"]*)"/', $opml, $matches);

        return new OpmlImportSelectionModel($opml, new OpmlPlacementChoiceModel($parent), $matches[1], []);
    }

    private static function feed(string $url): string
    {
        return '<outline type="rss" text="F" xmlUrl="' . $url . '"/>';
    }

    private function techFile(): string
    {
        return $this->opml('<outline text="Tech">' . self::feed('https://feed1.example/feed') . '</outline>');
    }

    private function storedTechHoldingAFeed(User $user): Tag
    {
        $tech = $this->storedTag($user, 'Tech', null);
        $feed = new Feed('https://stored-tech.example/feed');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->addTag($tech);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $tech;
    }

    private function subscriptionTo(User $user, string $url): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy([
            'user' => $user,
            'feed' => $this->feedsWithUrl($url)[0],
        ]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    /** @return list<string> sorted */
    private function tagNamesFor(User $user, string $url): array
    {
        $names = $this->tagNamesOf($this->subscriptionTo($user, $url));
        sort($names);

        return $names;
    }

    /** @return list<string> sorted */
    private function collectTagNames(User $user): array
    {
        $names = array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $this->entityManager->getRepository(Tag::class)->findBy(['user' => $user]),
        );
        sort($names);

        return $names;
    }

    /** @return list<Subscription> */
    private function subscriptionsTaggedWith(Tag $tag): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(Subscription::class)->findAll(),
            static fn (Subscription $subscription): bool => $subscription->getTags()->contains($tag),
        ));
    }

    private function findTagNamed(User $user, string $name): ?Tag
    {
        return $this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => $name]);
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
        $tag = $this->findTagNamed($user, $name);
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
