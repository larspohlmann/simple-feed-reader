<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use Dom\HTMLDocument;

/**
 * One in-place repair of a fetched page's document before readability scores it, for a real-world page defect that
 * would cost the extraction a figure, a heading or the whole article. FetchedPageNormalizer runs them in order.
 */
interface PageRepairInterface
{
    public function repairIn(HTMLDocument $document): void;
}
