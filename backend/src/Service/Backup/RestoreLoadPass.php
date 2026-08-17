<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Feed;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\BackupLoadFailedException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One restore's account-shaped half — settings, tags, feeds and subscriptions
 * — plus the dispatch over the whole line stream. Constructed per restore and
 * thrown away with it: the name ⇒ Tag and url ⇒ Feed maps it holds are
 * working state, which is exactly why they do not live on the autowired
 * RestoreLoader.
 *
 * The account is assumed to be freshly reset. Nothing here reads or updates a
 * row the wipe left behind.
 */
final class RestoreLoadPass
{
    /** @var array<string, Tag> */
    private array $tagsByName = [];

    /** @var array<string, Feed> */
    private array $feedsByUrl = [];

    /** @var array<string, Feed> the subset this restore actually subscribed to */
    private array $subscribedFeedsByUrl = [];

    /** @var array{tags: int, feeds: int, subscriptions: int} */
    private array $counts = ['tags' => 0, 'feeds' => 0, 'subscriptions' => 0];

    private bool $entryPhaseStarted = false;

    /** The account being loaded, re-acquired after the mid-pass clear(). */
    private User $user;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedRepository $feeds,
        private readonly EntryRepository $entries,
        private readonly RestoreEntryLoader $entryLoader,
    ) {
    }

    /**
     * @param \Generator<int, object> $lines
     */
    public function run(User $user, \Generator $lines): RestoreResult
    {
        $this->user = $user;
        foreach ($lines as $line) {
            $this->accept($line);
        }

        // A backup with no entries and no states still has to reach the entry
        // phase, so the account's own rows are flushed exactly once.
        $this->startEntryPhase();
        $this->entryLoader->finish();

        return new RestoreResult(
            tags: $this->counts['tags'],
            feeds: $this->counts['feeds'],
            subscriptions: $this->counts['subscriptions'],
            entries: $this->entryLoader->entriesCreated(),
            entryStates: $this->entryLoader->entryStatesCreated(),
        );
    }

    private function accept(object $line): void
    {
        match (true) {
            $line instanceof AccountLine => $this->loadAccount($line),
            $line instanceof TagLine => $this->loadTag($line),
            $line instanceof FeedLine => $this->loadFeed($line),
            $line instanceof SubscriptionLine => $this->loadSubscription($line),
            $line instanceof EntryLine => $this->acceptEntry($line),
            $line instanceof EntryStateLine => $this->acceptEntryState($line),
            // The header carries provenance for the preview, nothing to load.
            default => null,
        };
    }

    private function loadAccount(AccountLine $line): void
    {
        $this->user->setLocale($line->locale);
        $this->user->getPreferences()->setScrapeFallbackEnabled($line->scrapeFallbackEnabled);
        if (null === $line->recommendationSettings) {
            return;
        }

        $settings = new RecommendationSettings($this->user);
        $settings->update($line->recommendationSettings);
        $this->em->persist($settings);
    }

    private function loadTag(TagLine $line): void
    {
        $tag = new Tag($this->user, $line->name);
        $tag->setColor($line->color);
        $tag->setIcon($line->icon);
        $tag->setPosition($line->position);
        $this->em->persist($tag);
        $this->tagsByName[$line->name] = $tag;
        ++$this->counts['tags'];
    }

    /**
     * A feed row is shared between accounts, so a known one is referenced and
     * never touched — not even to improve a null title. sourceFormat is
     * therefore written only on a row this restore creates, which is
     * SubscriptionCreator's trust rule at its strictest: a value asserted by
     * an uploaded file may not overwrite what the instance already learned.
     */
    private function loadFeed(FeedLine $line): void
    {
        $known = $this->feeds->findOneBy(['url' => $line->url]);
        if ($known instanceof Feed) {
            $this->feedsByUrl[$line->url] = $known;

            return;
        }

        $feed = new Feed($line->url);
        $feed->setSiteUrl($line->siteUrl);
        $feed->setTitle($line->title);
        $feed->setDescription($line->description);
        $feed->setFaviconUrl($line->faviconUrl);
        $feed->setSourceFormat($line->sourceFormat);
        $this->em->persist($feed);
        $this->feedsByUrl[$line->url] = $feed;
        ++$this->counts['feeds'];
    }

    private function loadSubscription(SubscriptionLine $line): void
    {
        $feed = $this->feedsByUrl[$line->feedUrl] ?? throw BackupLoadFailedException::danglingReference(sprintf(
            'Subscription to "%s" has no matching feed line.',
            $line->feedUrl,
        ));

        $subscription = new Subscription($this->user, $feed, $line->createdAt);
        $subscription->setCustomTitle($line->customTitle);
        $subscription->setPosition($line->position);
        $subscription->setMarkedReadUntil($line->markedReadUntil);
        foreach ($line->tags as $ref) {
            $subscription->addTag($this->tagNamed($ref->name), $ref->position);
        }

        $this->em->persist($subscription);
        $this->subscribedFeedsByUrl[$line->feedUrl] = $feed;
        ++$this->counts['subscriptions'];
    }

    /**
     * A backstop: BackupInspector refuses an undeclared tag reference in pass
     * 1, while the account is still whole. Reaching this means the wipe has
     * already run, so the user must be told the account is empty.
     */
    private function tagNamed(string $name): Tag
    {
        return $this->tagsByName[$name] ?? throw BackupLoadFailedException::danglingReference(sprintf(
            'A subscription names tag "%s", which the backup never declares.',
            $name,
        ));
    }

    private function acceptEntry(EntryLine $line): void
    {
        $this->startEntryPhase();
        $this->entryLoader->bufferEntry($line);
    }

    private function acceptEntryState(EntryStateLine $line): void
    {
        $this->startEntryPhase();
        $this->entryLoader->loadState($line);
    }

    /**
     * The hand-over, and the one place the entity manager is cleared between
     * the two halves. Everything the entry phase needs is read HERE, while the
     * feeds are still managed and their ids already assigned — after the
     * clear() there is no entity left to read an id from, including the User
     * this pass was built with.
     */
    private function startEntryPhase(): void
    {
        if ($this->entryPhaseStarted) {
            return;
        }
        $this->entryPhaseStarted = true;

        try {
            $this->em->flush();
        } catch (DbalException $e) {
            throw BackupLoadFailedException::from($e);
        }

        $targets = $this->feedTargets();
        $userId = (int) $this->user->getId();
        $this->em->clear();
        $this->user = $this->em->getReference(User::class, $userId)
            ?? throw new \LogicException('The account disappeared during its own restore.');
        $this->entryLoader->begin($targets, $this->user);
    }

    /**
     * @return array<string, RestoreFeedTarget>
     */
    private function feedTargets(): array
    {
        $userId = (int) $this->user->getId();
        $targets = [];
        foreach ($this->subscribedFeedsByUrl as $url => $feed) {
            $feedId = (int) $feed->getId();
            $targets[$url] = new RestoreFeedTarget(
                $feedId,
                !$this->feeds->isReadByAnotherUser($feedId, $userId),
                $this->entries->guidHashToIdMapForFeed($feedId),
            );
        }

        return $targets;
    }
}
