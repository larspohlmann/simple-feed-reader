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
        return PostEnrichers::sharedLinkWriter($entityManager)->linkFor($preview, null);
    }
}
