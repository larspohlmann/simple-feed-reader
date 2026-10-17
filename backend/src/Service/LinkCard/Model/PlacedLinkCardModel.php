<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Entity\LinkCardContent;

final readonly class PlacedLinkCardModel
{
    public function __construct(public int $position, public LinkCardContent $content)
    {
    }
}
