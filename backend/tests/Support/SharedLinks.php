<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use Doctrine\ORM\EntityManagerInterface;

final class SharedLinks
{
    private function __construct()
    {
    }

    public static function of(EntityManagerInterface $entityManager, LinkCardContent $preview): SharedLink
    {
        return PostEnrichers::sharedLinkWriter($entityManager)->ensureLink($preview, null);
    }

    /** @return int the id of the stored link */
    public static function named(EntityManagerInterface $entityManager, string $url, string $siteName): int
    {
        return self::of($entityManager, new LinkCardContent($url, 'Title', null, null, $siteName))->requireId();
    }

    public static function siteNameOf(EntityManagerInterface $entityManager, int $linkId): mixed
    {
        return $entityManager->getConnection()->fetchOne('SELECT site_name FROM shared_link WHERE id = ?', [$linkId]);
    }
}
