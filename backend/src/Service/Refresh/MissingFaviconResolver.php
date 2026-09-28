<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FaviconResolver\FaviconResolverInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Looks up a favicon for each given feed that has none yet, fetching every homepage in one concurrent batch. */
final readonly class MissingFaviconResolver
{
    public function __construct(
        private FaviconResolverInterface $faviconResolver,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<Feed> $feeds
     */
    public function resolveFor(array $feeds): void
    {
        $baseUrls = self::baseUrlsOfFeedsWithoutIcon($feeds);
        if ([] === $baseUrls) {
            return;
        }

        $icons = $this->faviconResolver->resolveAll($baseUrls);
        foreach ($feeds as $feed) {
            $icon = $icons[$feed->requireId()] ?? null;
            if (null !== $icon) {
                $feed->setFaviconUrl($icon);
            }
        }

        $this->em->flush();
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return array<int, string>
     */
    private static function baseUrlsOfFeedsWithoutIcon(array $feeds): array
    {
        $baseUrls = [];
        foreach ($feeds as $feed) {
            if (null === $feed->getFaviconUrl()) {
                $baseUrls[$feed->requireId()] = $feed->getSiteUrl() ?? $feed->getUrl();
            }
        }

        return $baseUrls;
    }
}
