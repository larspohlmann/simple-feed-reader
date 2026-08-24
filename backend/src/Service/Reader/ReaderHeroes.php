<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The two pictures a reader response offers: one for each body the client can
 * put on screen. Serving both lets the Reader/Original toggle switch without a
 * request, and keeps the duplicate rule off every client (#592).
 */
final readonly class ReaderHeroes
{
    public function __construct(
        public ?HeroImage $readerHero,
        public ?HeroImage $originalHero,
    ) {
    }
}
