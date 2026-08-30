<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** How the magazine view draws an entry (#723). */
enum MagazineStyle: string
{
    case Boxed = 'boxed';
    case Airy = 'airy';
}
