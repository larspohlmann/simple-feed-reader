<?php

declare(strict_types=1);

namespace App\Service\Reader;

interface GatedMediaPlaceholderInterface
{
    /** Replace a gated media region with a poster placeholder; true if it acted. */
    public function replaceIn(\Dom\HTMLDocument $body, GatedMediaContext $context): bool;
}
