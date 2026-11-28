<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\SavedListExport;

use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\EntryState;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\Label;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Repository\EntryLabelRepository;
use App\Repository\EntryListRepository;
use App\Repository\EntryQuery;
use App\Repository\LabelScope;
use App\Service\Library\SavedListExport\Model\SavedListEntryModel;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;
use App\Service\Library\SavedListExport\SavedListWalk;
use App\Tests\Support\NamesLabels;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StateMarks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SavedListWalkTest extends KernelTestCase
{
    use NamesLabels;
    use SeedsUsers;

    private EntityManagerInterface $entityManager;
    private SavedListWalk $walk;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $entryList = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryList);
        $entryLabels = self::getContainer()->get(EntryLabelRepository::class);
        self::assertInstanceOf(EntryLabelRepository::class, $entryLabels);
        $this->walk = new SavedListWalk($entryList, $entryLabels, $entityManager);
    }

    public function testItYieldsTheKeptEntriesNewestFirst(): void
    {
        $user = $this->user('export-walk-newest@example.com');
        $feed = $this->subscribe($user);
        $this->entry($user, $feed, 'a', '01', kept: true);
        $this->entry($user, $feed, 'b', '02', kept: true);
        $this->entry($user, $feed, 'c', '03', kept: true);
        $this->entry($user, $feed, 'unkept', '04', kept: false);

        self::assertSame(['c', 'b', 'a'], $this->titles($user, $this->keptScope()));
    }

    public function testOldestFirstReversesTheOrder(): void
    {
        $user = $this->user('export-walk-oldest@example.com');
        $feed = $this->subscribe($user);
        $this->entry($user, $feed, 'a', '01', kept: true);
        $this->entry($user, $feed, 'b', '02', kept: true);

        self::assertSame(['a', 'b'], $this->titles($user, $this->keptScope(order: ListOrder::OldestFirst)));
    }

    public function testALabelNarrowsTheWalk(): void
    {
        $user = $this->user('export-walk-label@example.com');
        $feed = $this->subscribe($user);
        $work = $this->labelNamed($user, 'Work');
        $labelled = $this->entry($user, $feed, 'labelled', '02', kept: true);
        $this->entry($user, $feed, 'plain', '01', kept: true);
        $this->label($work, $labelled);
        $scope = new SavedListScopeModel(
            EntryView::Kept,
            LabelScope::forLabel($work->requireId()),
            $work->requireId(),
            'Work',
            ListOrder::NewestFirst,
        );

        self::assertSame(['labelled'], $this->titles($user, $scope));
    }

    public function testUnlabeledLeavesOutLabelledEntries(): void
    {
        $user = $this->user('export-walk-unlabeled@example.com');
        $feed = $this->subscribe($user);
        $work = $this->labelNamed($user, 'Work');
        $labelled = $this->entry($user, $feed, 'labelled', '02', kept: true);
        $this->entry($user, $feed, 'plain', '01', kept: true);
        $this->label($work, $labelled);
        $scope = new SavedListScopeModel(
            EntryView::Kept,
            LabelScope::unlabeled(),
            null,
            null,
            ListOrder::NewestFirst,
        );

        self::assertSame(['plain'], $this->titles($user, $scope));
    }

    public function testEveryEntryAppearsOnceAcrossABatchBoundary(): void
    {
        $user = $this->user('export-walk-boundary@example.com');
        $feed = $this->subscribe($user);
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($index = 0; $index <= EntryQuery::MAX_LIMIT; ++$index) {
            $this->persistEntry($user, $feed, 'same-' . $index, $moment, kept: true);
        }
        $this->entityManager->flush();

        foreach ([ListOrder::NewestFirst, ListOrder::OldestFirst] as $order) {
            $scope = $this->keptScope(order: $order);
            $batches = iterator_to_array($this->walk->batches($user->requireId(), $scope), false);
            $guids = array_map(
                static fn (SavedListEntryModel $entry): string => $entry->guid,
                array_merge(...$batches),
            );

            self::assertCount(EntryQuery::MAX_LIMIT, $batches[0]);
            self::assertCount(EntryQuery::MAX_LIMIT + 1, $guids);
            self::assertCount(EntryQuery::MAX_LIMIT + 1, array_unique($guids));
        }
    }

    public function testAnExactMultipleOfTheBatchSizeEndsCleanly(): void
    {
        $user = $this->user('export-walk-exact@example.com');
        $feed = $this->subscribe($user);
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($index = 0; $index < EntryQuery::MAX_LIMIT; ++$index) {
            $this->persistEntry($user, $feed, 'exact-' . $index, $moment, kept: true);
        }
        $this->entityManager->flush();

        $batches = iterator_to_array($this->walk->batches($user->requireId(), $this->keptScope()), false);

        self::assertCount(1, $batches);
        self::assertCount(EntryQuery::MAX_LIMIT, $batches[0]);
    }

    public function testAnotherUsersEntriesNeverAppear(): void
    {
        $user = $this->user('export-walk-own@example.com');
        $stranger = $this->user('export-walk-stranger@example.com');
        $this->entry($user, $this->subscribe($user), 'mine', '01', kept: true);
        $this->entry($stranger, $this->subscribe($stranger), 'theirs', '02', kept: true);

        self::assertSame(['mine'], $this->titles($user, $this->keptScope()));
    }

    public function testItCarriesLabelsContentAndOrigin(): void
    {
        $user = $this->user('export-walk-carry@example.com');
        $feed = new Feed('https://carry.example/feed.xml');
        $feed->setSiteUrl('https://carry.example/');
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->setCustomTitle('My carry feed');
        $this->entityManager->persist($subscription);
        $full = $this->entry($user, $feed, 'full', '02', kept: true);
        $full->recordText(new EntryText('summary', '<p>body</p>'));
        $summaryOnly = $this->entry($user, $feed, 'summary-only', '01', kept: true);
        $summaryOnly->recordText(new EntryText('summary'));
        $this->label($this->labelNamed($user, 'Beta'), $full);
        $this->label($this->labelNamed($user, 'Alpha'), $full);
        $this->entityManager->flush();

        $models = $this->entries($user, $this->keptScope());

        self::assertSame(['Alpha', 'Beta'], $models[0]->labelNames);
        self::assertSame('<p>body</p>', $models[0]->contentHtml);
        self::assertSame('summary', $models[1]->contentHtml);
        self::assertSame([], $models[1]->labelNames);
        self::assertSame('My carry feed', $models[0]->origin->title);
        self::assertSame('https://carry.example/feed.xml', $models[0]->origin->feedUrl);
        self::assertSame('https://carry.example/', $models[0]->origin->siteUrl);
    }

    private function keptScope(ListOrder $order = ListOrder::NewestFirst): SavedListScopeModel
    {
        return new SavedListScopeModel(EntryView::Kept, null, null, null, $order);
    }

    /**
     * @return list<SavedListEntryModel>
     */
    private function entries(User $user, SavedListScopeModel $scope): array
    {
        $batches = iterator_to_array($this->walk->batches($user->requireId(), $scope), false);

        return array_merge([], ...$batches);
    }

    /**
     * @return list<string>
     */
    private function titles(User $user, SavedListScopeModel $scope): array
    {
        return array_map(
            static fn (SavedListEntryModel $entry): string => $entry->title,
            $this->entries($user, $scope),
        );
    }

    private function subscribe(User $user): Feed
    {
        $feed = new Feed('https://export-walk.example/' . uniqid('', true) . '.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(User $user, Feed $feed, string $title, string $day, bool $kept): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-' . $day . 'T00:00:00Z');
        $entry = $this->persistEntry($user, $feed, $title, $moment, $kept);
        $this->entityManager->flush();

        return $entry;
    }

    private function persistEntry(User $user, Feed $feed, string $title, \DateTimeImmutable $moment, bool $kept): Entry
    {
        $guid = uniqid($title, true);
        $entry = new Entry($feed, $guid, 'https://export-walk.example/' . $guid, $title, $moment, $moment);
        $this->entityManager->persist($entry);
        $state = new EntryState($user, $entry);
        if ($kept) {
            StateMarks::markKept($state);
        }
        $this->entityManager->persist($state);

        return $entry;
    }

    private function label(Label $label, Entry $entry): void
    {
        $this->entityManager->persist(new EntryLabel($label, $entry));
        $this->entityManager->flush();
    }
}
