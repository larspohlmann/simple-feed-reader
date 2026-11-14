<?php

declare(strict_types=1);

namespace App\Service\Image\ProbedLadder;

use App\Entity\ImageRendition;

interface ProbedLadderInterface
{
    /**
     * The renditions an entry judged earlier stored for this image URL, empty when its probe found none; null when
     * no entry with this image has been judged.
     *
     * @return list<ImageRendition>|null
     */
    public function findProbedLadder(string $imageUrl): ?array;
}
