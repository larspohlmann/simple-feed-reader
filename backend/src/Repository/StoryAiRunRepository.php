<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StoryAiRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoryAiRun> */
final class StoryAiRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoryAiRun::class);
    }
}
