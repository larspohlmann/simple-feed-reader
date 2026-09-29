<?php

declare(strict_types=1);

namespace App\Service\Backup\Pass;

use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\Factory\RestoredFoundationFactory;
use App\Service\Backup\Model\RestoreResultModel;
use App\Service\Backup\RestoreFeeds\RestoreFeedsInterface;
use App\Service\Search\SavedSearchSlug;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The foundation's load (settings, tags, saved searches, feeds, subscriptions), built per restore for its name => Tag
 * and url => Feed maps. It assumes a freshly reset account; entries and states belong to EntryPartRestorer.
 */
final class RestoreLoadPass
{
    /** @var array<string, Tag> */
    private array $tagsByName = [];

    /** @var array<string, Feed> */
    private array $feedsByUrl = [];

    /** @var list<FeedLine> held back until one lookup resolves them all */
    private array $heldFeedLines = [];

    /** @var list<SavedSearch> held back until the flush that assigns their id */
    private array $loadedSavedSearches = [];

    /** @var array{tags: int, savedSearches: int, feeds: int, subscriptions: int} */
    private array $counts = ['tags' => 0, 'savedSearches' => 0, 'feeds' => 0, 'subscriptions' => 0];

    private User $user;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RestoreFeedsInterface $feeds,
        private readonly SavedSearchSlug $slug,
        private readonly RestoredFoundationFactory $foundationFactory,
    ) {
    }

    /**
     * @param \Generator<int, object> $lines
     */
    public function run(User $user, \Generator $lines): RestoreResultModel
    {
        $this->user = $user;
        foreach ($lines as $line) {
            $this->accept($line);
        }
        $this->resolveHeldFeeds();
        $this->flush();
        $this->regenerateSavedSearchSlugs();

        return RestoreResultModel::ofFoundation(
            tags: $this->counts['tags'],
            savedSearches: $this->counts['savedSearches'],
            feeds: $this->counts['feeds'],
            subscriptions: $this->counts['subscriptions'],
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
        $tag = $this->foundationFactory->tag($this->user, $line);
        $this->entityManager->persist($tag);
        $this->tagsByName[$line->name] = $tag;
        ++$this->counts['tags'];
    }

    private function loadSavedSearch(SavedSearchLine $line): void
    {
        $savedSearch = new SavedSearch($this->user, $line->term, $line->wholeWord, $line->phrase);
        $savedSearch->setPosition($line->position);
        $this->entityManager->persist($savedSearch);
        $this->loadedSavedSearches[] = $savedSearch;
        ++$this->counts['savedSearches'];
    }

    /** The slug embeds the row's id, so it is built only after the flush; the file's own id is never restored. */
    private function regenerateSavedSearchSlugs(): void
    {
        if ([] === $this->loadedSavedSearches) {
            return;
        }

        foreach ($this->loadedSavedSearches as $savedSearch) {
            $this->slug->assignTo($savedSearch);
        }
        $this->flush();
    }

    private function holdFeed(FeedLine $line): void
    {
        $this->heldFeedLines[] = $line;
    }

    /**
     * BackupReader puts every feed line before the first subscription, so one query resolves the file's whole set.
     * A known feed row is shared: it is referenced, never updated, so a file's sourceFormat reaches only new rows.
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
        $feed = $this->foundationFactory->feed($line);
        $this->entityManager->persist($feed);
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

        $subscription = $this->foundationFactory->subscription($this->user, $feed, $line);
        foreach ($line->tags as $tagReference) {
            $subscription->addTag($this->tagNamed($tagReference->name), $tagReference->position);
        }

        $this->entityManager->persist($subscription);
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
            $this->entityManager->flush();
        } catch (DbalException $exception) {
            throw BackupLoadFailedException::from($exception);
        }
    }
}
