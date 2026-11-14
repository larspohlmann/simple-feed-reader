<?php

declare(strict_types=1);

// Fixtures for ServiceModuleBoundaryRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Content\Reader\Fixtures {
    use App\Service\Library\Search\SearchTerms;

    final class ReaderSearches
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Curation\Recommendation\Run\Fixtures {
    final class RecommendationReads
    {
        public function extractor(): string
        {
            return \App\Service\Content\Reader\ArticleExtractor::class;
        }
    }
}

namespace App\Service\Library\Reading\Fixtures {
    use App\Service\Library\Search\SearchTerms;

    final class ReadingMaySearch
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Content\ReaderAudit\Fixtures {
    use App\Service\Library\Search\SearchTerms;

    final class TheAuditIsNotTheReader
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Curation\Recommendation\Feed\Fixtures {
    use App\Service\Content\ReaderAudit\ReaderAuditRunner;

    final class RecommendationMayNameALookalike
    {
        public function __construct(public ReaderAuditRunner $runner)
        {
        }
    }
}

namespace App\Service\Library\Reading\Fixtures\TimeZone {
    final class ReadingKnowsRecommendations
    {
        public function zone(): string
        {
            return \App\Service\Curation\Recommendation\Feed\Model\ViewerTimeZoneModel::class;
        }
    }
}
