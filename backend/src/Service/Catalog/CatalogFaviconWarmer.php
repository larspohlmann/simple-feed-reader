<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Repository\CatalogFaviconDueCriteria;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Model\CatalogWarmReportModel;
use App\Service\Fetch\FaviconResolver\FaviconResolverInterface;
use App\Service\Image\Exception\FaviconUnavailableException;
use App\Service\Image\FaviconFetcher\FaviconFetcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Fills missing and stale catalog favicons a budgeted slice at a time (the caller comes back for `remaining`): one
 * concurrent resolve per slice, then a download and a commit per row. No lock: concurrent runs only redo a little.
 */
final readonly class CatalogFaviconWarmer
{
    private const string STALE_AFTER = 'P90D';
    private const string RETRY_FAILURES_AFTER = 'P14D';
    private const int BATCH_LIMIT = 25;

    public function __construct(
        private CatalogFeedRepository $feeds,
        private FaviconResolverInterface $faviconResolver,
        private FaviconFetcherInterface $fetcher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \DateInvalidOperationException
     */
    public function warm(int $budgetSeconds, ?int $limit = null): CatalogWarmReportModel
    {
        $now = $this->clock->now();
        $deadline = $now->getTimestamp() + $budgetSeconds;
        $criteria = $this->dueCriteriaAt($now);

        $due = $this->feeds->findNeedingFavicon($criteria, $limit ?? self::BATCH_LIMIT);

        // Resolve the whole slice's icon URLs up front, in one concurrent burst.
        // `$due` is a list, so its 0..n keys line the resolved URLs up with the
        // feeds below. resolveAll never throws and returns a URL (or null) per key.
        $iconUrls = $this->faviconResolver->resolveAll(
            array_map(static fn (CatalogFeed $feed): string => $feed->getSiteUrl() ?? $feed->getUrl(), $due),
        );

        $warmed = 0;
        $failed = 0;
        foreach ($due as $index => $feed) {
            $this->store($feed, $iconUrls[$index] ?? null, $now) ? ++$warmed : ++$failed;

            // Checked after the download, never before: stopping early would report progress it did not make.
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                break;
            }
        }

        return new CatalogWarmReportModel(
            $warmed,
            $failed,
            $this->feeds->countNeedingFavicon($criteria),
        );
    }

    /**
     * @throws \DateInvalidOperationException
     */
    private function dueCriteriaAt(\DateTimeImmutable $now): CatalogFaviconDueCriteria
    {
        return new CatalogFaviconDueCriteria(
            $now->sub(new \DateInterval(self::STALE_AFTER)),
            $now->sub(new \DateInterval(self::RETRY_FAILURES_AFTER)),
        );
    }

    /**
     * Force path: mark every enabled row for re-warming. Call ONCE, then loop
     * warm(): the normal P90D/P14D window lets each row leave the due set as it
     * is re-warmed, so the loop converges rather than re-downloading forever.
     */
    public function markAllForReWarming(): void
    {
        $this->feeds->resetFaviconFreshness();
    }

    /**
     * Re-fetch one row's icon on demand — the admin "refresh favicon" action.
     * Resolves this one site (a one-item batch) and downloads through the same
     * guarded path warming uses, so the two callers cannot drift apart.
     */
    public function refresh(CatalogFeed $feed): void
    {
        $iconUrl = $this->faviconResolver->resolveAll([$feed->getSiteUrl() ?? $feed->getUrl()])[0] ?? null;
        $this->store($feed, $iconUrl, $this->clock->now());
    }

    /**
     * Stores one resolved icon, or records a failure when the URL is unresolved or the download refused; it commits
     * per row so an interrupted run resumes. Returns whether an icon was stored.
     */
    private function store(CatalogFeed $feed, ?string $iconUrl, \DateTimeImmutable $now): bool
    {
        if (null !== $iconUrl) {
            try {
                $icon = $this->fetcher->download($iconUrl);
                $feed->storeFavicon($icon->sourceUrl, $icon->bytes, $icon->contentType, $now);
                $this->entityManager->flush();

                return true;
            } catch (FaviconUnavailableException) {
                // Fall through: an undownloadable icon is a recorded failure.
            }
        }

        $feed->recordFaviconFailure($now);
        $this->entityManager->flush();

        return false;
    }
}
