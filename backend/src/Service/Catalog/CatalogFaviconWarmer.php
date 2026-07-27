<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\FaviconResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Fills in missing and stale catalog favicons, a budgeted slice at a time.
 *
 * Each slice resolves its icon URLs in a single concurrent batch through the
 * shared `FaviconResolver::resolveAll()` (see #116) — one burst of guarded
 * homepage fetches rather than 25 sequential ones — then downloads each icon's
 * bytes and commits per row.
 *
 * Budgeted because 111 publisher round trips cannot happen inside one HTTP
 * request: the caller gets `remaining` back and comes again, exactly as
 * /api/refresh works. The console command passes a large budget and simply
 * loops itself.
 *
 * Deliberately NOT tied to any deployment mechanism. The admin UI drives it
 * after an import, so a self-hosted install with no deploy script gets icons
 * the same way this project's own server does.
 *
 * No lock: each row commits on its own and the due-query skips anything already
 * fresh, so two concurrent runs merely duplicate a little work rather than
 * corrupting anything.
 */
final readonly class CatalogFaviconWarmer
{
    private const string STALE_AFTER = 'P90D';
    private const string RETRY_FAILURES_AFTER = 'P14D';
    private const int BATCH_LIMIT = 25;

    public function __construct(
        private CatalogFeedRepository $feeds,
        private FaviconResolverInterface $faviconResolver,
        private CatalogFaviconFetcherInterface $fetcher,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function warm(int $budgetSeconds, ?int $limit = null): CatalogWarmReport
    {
        $now = $this->clock->now();
        $deadline = $now->getTimestamp() + $budgetSeconds;
        [$staleBefore, $retryBefore] = $this->windows($now);

        $due = $this->feeds->findNeedingFavicon($staleBefore, $retryBefore, $limit ?? self::BATCH_LIMIT);

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

            // Check AFTER the download, never before: a budget that stops early
            // would report progress it did not make. One overshoot by a single
            // icon's timeout is the price of an honest count. (Resolution already
            // happened above as one bounded burst, so the loop only downloads.)
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                break;
            }
        }

        return new CatalogWarmReport(
            $warmed,
            $failed,
            $this->feeds->countNeedingFavicon($staleBefore, $retryBefore),
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function windows(\DateTimeImmutable $now): array
    {
        return [
            $now->sub(new \DateInterval(self::STALE_AFTER)),
            $now->sub(new \DateInterval(self::RETRY_FAILURES_AFTER)),
        ];
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
     * Downloads and stores one already-resolved icon, or records a failure when
     * the URL is unresolved or the download is rejected. Commits per row so an
     * interrupted run resumes rather than restarting. Returns whether an icon
     * was stored.
     */
    private function store(CatalogFeed $feed, ?string $iconUrl, \DateTimeImmutable $now): bool
    {
        if (null !== $iconUrl) {
            try {
                $icon = $this->fetcher->download($iconUrl);
                $feed->storeFavicon($icon->sourceUrl, $icon->bytes, $icon->contentType, $now);
                $this->em->flush();

                return true;
            } catch (FaviconUnavailableException) {
                // Fall through: an undownloadable icon is a recorded failure.
            }
        }

        $feed->recordFaviconFailure($now);
        $this->em->flush();

        return false;
    }
}
