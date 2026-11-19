<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Support;

use App\Entity\SavedSearch;
use App\Service\Library\Search\Model\SavedSearchTermModel;
use App\Service\Library\Search\Model\SearchMode;
use App\Service\Library\Search\Model\SearchTermsModel;

/**
 * A saved search's stored shape — a bare term plus two mode columns — read as
 * the SearchTermsModel the search domain runs on. One mapping, so every matcher
 * reads a saved search the same way.
 */
final readonly class SavedSearchTerms
{
    public static function fromSavedSearch(SavedSearch $savedSearch): SearchTermsModel
    {
        return SearchTermsModel::fromTermAndMode(
            $savedSearch->getTerm(),
            SearchMode::fromFlags($savedSearch->isWholeWord(), $savedSearch->isPhrase()),
        );
    }

    public static function buildTermModel(SavedSearch $savedSearch): SavedSearchTermModel
    {
        return new SavedSearchTermModel($savedSearch->requireId(), self::fromSavedSearch($savedSearch));
    }

    private function __construct()
    {
    }
}
