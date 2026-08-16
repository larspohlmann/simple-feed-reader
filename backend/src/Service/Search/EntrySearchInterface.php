<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntrySearchQuery;

/**
 * Finds entries for one caller. The single seam behind which the matching lives:
 * swapping LIKE for Elasticsearch or Solr means adding an implementation here
 * and changing one alias, and nothing above this line moves.
 */
interface EntrySearchInterface
{
    /** @return EntrySearchResult whose rows are newest first, at most $query->limit of them */
    public function search(EntrySearchQuery $query): EntrySearchResult;
}
