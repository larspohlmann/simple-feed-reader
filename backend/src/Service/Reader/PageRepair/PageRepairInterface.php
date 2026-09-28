<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use Dom\HTMLDocument;

/**
 * One repair applied to a fetched page's parsed document before readability
 * scores it — a defect of a real-world site that would otherwise cost the
 * extraction a figure, a heading or a whole article. FetchedPageNormalizer runs
 * the repairs in order; each mutates the document in place.
 */
interface PageRepairInterface
{
    public function repairIn(HTMLDocument $document): void;
}
