<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\BackupLoadFailedException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The foundation's own load: settings, tags, saved searches, feeds and
 * subscriptions, plus the dispatch over the line stream. Constructed per
 * restore and thrown away with it: the name ⇒ Tag and url ⇒ Feed maps it
 * holds are working state, which is exactly why they do not live on the
 * autowired RestoreLoader.
 *
 * The account is assumed to be freshly reset. Nothing here reads or updates a
 * row the wipe left behind. Entries and entry states arrive through a
 * separate part and a separate loader (Task 5); this pass never sees them.
 */
final class RestoreLoadPass
{
    /** @var array<string, Tag> */
    private array $tagsByName = [];

    /** @var array<string, Feed> */
    private array $feedsByUrl = [];

    /** @var list<FeedLine> held back until one lookup resolves them all (#455) */
    private array $heldFeedLines = [];

    /** @var array{tags: int, savedSearches: int, feeds: int, subscriptions: int} */
    private array $counts = ['tags' => 0, 'savedSearches' => 0, 'feeds' => 0, 'subscriptions' => 0];

    private User $user;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedRepository $feeds,
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
        $this->resolveHeldFeeds();
        $this->flush();

        return new RestoreResult(
            tags: $this->counts['tags'],
            savedSearches: $this->counts['savedSearches'],
            feeds: $this->counts['feeds'],
            subscriptions: $this->counts['subscriptions'],
            entries: 0,
            entryStates: 0,
        );
    }

    private function accept(object $line): void
    {
        match (true) {
            $line instanceof AccountLine => $this->loadAccount($line),
            $line instanceof TagLine => $this->loadTag($line),
            $line instanceof SavedSearchLine => $this->loadSavedSearch($line),
            $line instanceof FeedLine => $this->holdFeed($line),
            $line instanceof SubscriptionLine => $this->loadSubscription($line),
            // The header carries provenance for the preview, nothing to load.
            default => null,
        };
    }

    private function loadAccount(AccountLine $line): void
    {
        $this->user->setLocale($line->locale);
        $this->user->getPreferences()->setScrapeFallbackEnabled($line->scrapeFallbackEnabled);
        $this->user->getPreferences()->setMagazineStyle($line->magazineStyle);
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

    private function loadSavedSearch(SavedSearchLine $line): void
    {
        $savedSearch = new SavedSearch($this->user, $line->term, $line->wholeWord, $line->phrase);
        $savedSearch->setPosition($line->position);
        $this->em->persist($savedSearch);
        ++$this->counts['savedSearches'];
    }

    private function holdFeed(FeedLine $line): void
    {
        $this->heldFeedLines[] = $line;
    }

    /**
     * BackupReader puts every feed line before the first subscription, so by
     * the time anything needs a Feed the file's whole set is known and one
     * query resolves it (#455).
     *
     * A feed row is shared between accounts, so a known one is referenced and
     * never touched — not even to improve a null title. sourceFormat is
     * therefore written only on a row this restore creates, which is
     * SubscriptionCreator's trust rule at its strictest: a value asserted by
     * an uploaded file may not overwrite what the instance already learned.
     */
    private function resolveHeldFeeds(): void
    {
        $lines = $this->heldFeedLines;
        $this->heldFeedLines = [];
        if ([] === $lines) {
            return;
        }

        $urls = array_map(static fn (FeedLine $line): string => $line->url, $lines);
        $this->feedsByUrl += $this->feeds->findByUrlsIndexedByUrl($urls);
        foreach ($lines as $line) {
            $this->feedsByUrl[$line->url] ??= $this->createFeed($line);
        }
    }

    private function createFeed(FeedLine $line): Feed
    {
        $feed = new Feed($line->url);
        $feed->setSiteUrl($line->siteUrl);
        $feed->setTitle($line->title);
        $feed->setDescription($line->description);
        $feed->setFaviconUrl($line->faviconUrl);
        $feed->setImageUrl($line->imageUrl);
        $feed->setSourceFormat($line->sourceFormat);
        $this->em->persist($feed);
        ++$this->counts['feeds'];

        return $feed;
    }

    private function loadSubscription(SubscriptionLine $line): void
    {
        $this->resolveHeldFeeds();
        $feed = $this->feedsByUrl[$line->feedUrl] ?? throw BackupLoadFailedException::danglingReference(sprintf(
            'Subscription to "%s" has no matching feed line.',
            $line->feedUrl,
        ));

        $subscription = new Subscription($this->user, $feed, $line->createdAt);
        $subscription->setCustomTitle($line->customTitle);
        $subscription->setPosition($line->position);
        $subscription->setMarkedReadUntil($line->markedReadUntil);
        $subscription->setIncludeInAllItems($line->includeInAllItems);
        $subscription->setIncludeInForYou($line->includeInForYou);
        foreach ($line->tags as $ref) {
            $subscription->addTag($this->tagNamed($ref->name), $ref->position);
        }

        $this->em->persist($subscription);
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

    private function flush(): void
    {
        try {
            $this->em->flush();
        } catch (DbalException $e) {
            throw BackupLoadFailedException::from($e);
        }
    }
}
