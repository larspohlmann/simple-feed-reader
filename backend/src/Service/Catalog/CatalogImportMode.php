<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * The two import modes differ in exactly one respect: what happens to rows the
 * document does not mention. Both upsert what it does mention, and both keep the
 * cached favicon of any feed whose URL survives.
 */
enum CatalogImportMode: string
{
    /** Rows the document does not mention are left alone. */
    case Merge = 'merge';

    /** Rows the document does not mention are deleted. */
    case Replace = 'replace';
}
