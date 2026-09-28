<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use Doctrine\ORM\EntityManagerInterface;

/** One import's working state: the rows found by natural key, what the document mentioned, and the counts so far. */
final class CatalogImportPass
{
    private CatalogImportResult $result;

    /** @var array<string, CatalogCategory> */
    private array $categoriesByKey = [];

    /** @var array<string, CatalogFeed> */
    private array $feedsByUrl = [];

    /** @var array<string, CatalogDocumentCategory> */
    private array $mentionedCategories = [];

    /** @var array<string, CatalogDocumentFeed> */
    private array $mentionedFeeds = [];

    /** @var array<string, CatalogFeed> removing one of these categories would cascade to its locked feed */
    private array $lockedFeedsByCategoryKey = [];

    /**
     * @param array<CatalogCategory> $categories
     * @param array<CatalogFeed>     $feeds
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        array $categories,
        array $feeds,
    ) {
        $this->result = new CatalogImportResult();
        foreach ($categories as $category) {
            $this->categoriesByKey[$category->getKey()] = $category;
        }
        foreach ($feeds as $feed) {
            $this->feedsByUrl[$feed->getUrl()] = $feed;
            if ($feed->isLocked()) {
                $this->lockedFeedsByCategoryKey[$feed->getCategory()->getKey()] = $feed;
            }
        }
    }

    public function result(): CatalogImportResult
    {
        return $this->result;
    }

    public function apply(ParsedCatalog $document): void
    {
        foreach ($document->categories as $position => $documentCategory) {
            $category = $this->applyCategory($documentCategory, $position);
            foreach ($documentCategory->feeds as $feedPosition => $documentFeed) {
                $this->applyFeed($documentFeed, $category, $feedPosition);
            }
        }
    }

    public function removeUnmentioned(): void
    {
        $this->removeUnmentionedFeeds();
        $this->removeUnmentionedCategories();
    }

    private function applyCategory(CatalogDocumentCategory $documentCategory, int $position): CatalogCategory
    {
        $this->mentionedCategories[$documentCategory->key] = $documentCategory;
        $existing = $this->categoriesByKey[$documentCategory->key] ?? null;
        if (null === $existing) {
            return $this->createCategory($documentCategory, $position);
        }
        if ($existing->isLocked()) {
            // Locking protects the category row, not the catalog membership underneath it.
            $this->result = $this->result->with(lockedSkipped: 1);

            return $existing;
        }

        $existing->setName($documentCategory->name);
        $existing->setIcon($documentCategory->icon);
        $existing->setColor($documentCategory->color);
        $existing->setPosition($position);
        $this->result = $this->result->with(categoriesUpdated: 1);

        return $existing;
    }

    private function createCategory(CatalogDocumentCategory $documentCategory, int $position): CatalogCategory
    {
        $category = new CatalogCategory(
            $documentCategory->key,
            $documentCategory->name,
            $documentCategory->icon,
            $documentCategory->color,
        );
        $category->setPosition($position);
        $this->em->persist($category);
        $this->result = $this->result->with(categoriesCreated: 1);

        return $category;
    }

    private function applyFeed(CatalogDocumentFeed $documentFeed, CatalogCategory $category, int $position): void
    {
        $this->mentionedFeeds[$documentFeed->url] = $documentFeed;
        $existing = $this->feedsByUrl[$documentFeed->url] ?? null;
        if (null !== $existing && $existing->isLocked()) {
            $this->result = $this->result->with(lockedSkipped: 1);

            return;
        }

        $feed = null === $existing
            ? $this->createFeed($documentFeed, $category)
            : $this->updateFeed($existing, $documentFeed, $category);
        $feed->setSiteUrl($documentFeed->siteUrl);
        $feed->setDescription($documentFeed->description);
        $feed->setSourceFormat($documentFeed->sourceFormat);
        $feed->setPosition($position);
    }

    private function createFeed(CatalogDocumentFeed $documentFeed, CatalogCategory $category): CatalogFeed
    {
        $feed = new CatalogFeed($category, $documentFeed->title, $documentFeed->url);
        $this->em->persist($feed);
        $this->result = $this->result->with(feedsCreated: 1);

        return $feed;
    }

    private function updateFeed(
        CatalogFeed $feed,
        CatalogDocumentFeed $documentFeed,
        CatalogCategory $category,
    ): CatalogFeed {
        $feed->setTitle($documentFeed->title);
        $feed->setCategory($category);
        $this->result = $this->result->with(feedsUpdated: 1);

        return $feed;
    }

    private function removeUnmentionedFeeds(): void
    {
        foreach ($this->feedsByUrl as $feed) {
            $this->removeFeedUnlessMentioned($feed);
        }
    }

    private function removeFeedUnlessMentioned(CatalogFeed $feed): void
    {
        if (isset($this->mentionedFeeds[$feed->getUrl()])) {
            return;
        }
        if ($feed->isLocked()) {
            $this->result = $this->result->with(lockedSkipped: 1);

            return;
        }

        $this->em->remove($feed);
        $this->result = $this->result->with(feedsRemoved: 1);
    }

    private function removeUnmentionedCategories(): void
    {
        foreach ($this->categoriesByKey as $category) {
            $key = $category->getKey();
            if (isset($this->mentionedCategories[$key])) {
                continue;
            }
            if (isset($this->lockedFeedsByCategoryKey[$key]) || $category->isLocked()) {
                $this->result = $this->result->with(lockedSkipped: 1);

                continue;
            }
            // Its remaining feeds go with it through ON DELETE CASCADE; every locked feed kept its category above.
            $this->em->remove($category);
            $this->result = $this->result->with(categoriesRemoved: 1);
        }
    }
}
