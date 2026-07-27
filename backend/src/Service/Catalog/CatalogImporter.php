<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a validated catalog document to the database.
 *
 * Matching is by natural key — category by `key`, feed by `url` — never by id,
 * because the document has no ids and an admin may have edited the rows since
 * the last import. A feed whose URL survives keeps its row, and therefore its
 * cached favicon: re-importing must not cost a full re-warm.
 *
 * LOCKED rows belong to the admin, not the document: never overwritten, never
 * removed. A category holding a locked feed is protected too, or the cascade
 * would delete the very row the lock was protecting.
 *
 * The whole import runs in one transaction. A document is validated by
 * CatalogDocument before it gets here, so a failure at this point is
 * infrastructural, and a half-applied catalog is worse than none.
 */
final readonly class CatalogImporter
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
        private EntityManagerInterface $em,
    ) {
    }

    public function import(ParsedCatalog $document, CatalogImportMode $mode): CatalogImportResult
    {
        return $this->em->wrapInTransaction(function () use ($document, $mode): CatalogImportResult {
            $result = new CatalogImportResult();

            /** @var array<string, CatalogCategory> $existingCategories keyed by category key */
            $existingCategories = [];
            foreach ($this->categories->findAllOrdered() as $category) {
                $existingCategories[$category->getKey()] = $category;
            }

            /** @var array<string, CatalogFeed> $existingFeeds keyed by url */
            $existingFeeds = [];
            foreach ($this->feeds->findAll() as $feed) {
                $existingFeeds[$feed->getUrl()] = $feed;
            }

            $keptCategoryKeys = [];
            $keptFeedUrls = [];

            foreach ($document->categories as $position => $documentCategory) {
                $category = $existingCategories[$documentCategory->key] ?? null;
                if (null === $category) {
                    $category = new CatalogCategory(
                        $documentCategory->key,
                        $documentCategory->name,
                        $documentCategory->icon,
                        $documentCategory->color,
                    );
                    $category->setPosition($position);
                    $this->em->persist($category);
                    $result = $result->with(categoriesCreated: 1);
                } elseif ($category->isLocked()) {
                    // Locked: the row is the admin's. Its feeds are still
                    // processed — locking a category protects the category, not
                    // the membership of the catalog underneath it.
                    $result = $result->with(lockedSkipped: 1);
                } else {
                    $category->setName($documentCategory->name);
                    $category->setIcon($documentCategory->icon);
                    $category->setColor($documentCategory->color);
                    $category->setPosition($position);
                    $result = $result->with(categoriesUpdated: 1);
                }
                $keptCategoryKeys[$documentCategory->key] = true;

                foreach ($documentCategory->feeds as $feedPosition => $documentFeed) {
                    $result = $this->applyFeed(
                        $documentFeed,
                        $category,
                        $feedPosition,
                        $existingFeeds[$documentFeed->url] ?? null,
                        $result,
                    );
                    $keptFeedUrls[$documentFeed->url] = true;
                }
            }

            if (CatalogImportMode::Replace === $mode) {
                $result = $this->removeUnmentioned(
                    $existingCategories,
                    $existingFeeds,
                    $keptCategoryKeys,
                    $keptFeedUrls,
                    $result,
                );
            }

            $this->em->flush();

            return $result;
        });
    }

    private function applyFeed(
        CatalogDocumentFeed $documentFeed,
        CatalogCategory $category,
        int $position,
        ?CatalogFeed $existing,
        CatalogImportResult $result,
    ): CatalogImportResult {
        if (null !== $existing && $existing->isLocked()) {
            // The admin's row. Not overwritten — and, because the caller has
            // already recorded its URL as kept, not removed either.
            return $result->with(lockedSkipped: 1);
        }

        if (null === $existing) {
            $feed = new CatalogFeed($category, $documentFeed->title, $documentFeed->url);
            $this->em->persist($feed);
            $result = $result->with(feedsCreated: 1);
        } else {
            // Matched on URL, so the row — and its cached favicon — survives.
            $feed = $existing;
            $feed->setTitle($documentFeed->title);
            $feed->setCategory($category);
            $result = $result->with(feedsUpdated: 1);
        }

        $feed->setSiteUrl($documentFeed->siteUrl);
        $feed->setDescription($documentFeed->description);
        $feed->setSourceFormat($documentFeed->sourceFormat);
        $feed->setPosition($position);

        return $result;
    }

    /**
     * @param array<string, CatalogCategory> $existingCategories
     * @param array<string, CatalogFeed>     $existingFeeds
     * @param array<string, true>            $keptCategoryKeys
     * @param array<string, true>            $keptFeedUrls
     */
    private function removeUnmentioned(
        array $existingCategories,
        array $existingFeeds,
        array $keptCategoryKeys,
        array $keptFeedUrls,
        CatalogImportResult $result,
    ): CatalogImportResult {
        // Categories that must survive because a locked feed lives in them —
        // removing one would cascade and take the locked feed with it, which
        // would defeat the lock.
        $holdingALockedFeed = [];

        foreach ($existingFeeds as $url => $feed) {
            if ($feed->isLocked()) {
                $holdingALockedFeed[$feed->getCategory()->getKey()] = true;
                if (!isset($keptFeedUrls[$url])) {
                    $result = $result->with(lockedSkipped: 1);
                }

                continue;
            }
            if (isset($keptFeedUrls[$url])) {
                continue;
            }
            $this->em->remove($feed);
            $result = $result->with(feedsRemoved: 1);
        }

        foreach ($existingCategories as $key => $category) {
            if (isset($keptCategoryKeys[$key])) {
                continue;
            }
            if (isset($holdingALockedFeed[$key]) || $category->isLocked()) {
                $result = $result->with(lockedSkipped: 1);

                continue;
            }
            // Its remaining feeds go with it via the FK's ON DELETE CASCADE —
            // safe, because every locked feed has already reserved its category.
            $this->em->remove($category);
            $result = $result->with(categoriesRemoved: 1);
        }

        return $result;
    }
}
