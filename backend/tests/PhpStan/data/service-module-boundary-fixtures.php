<?php

declare(strict_types=1);

// Fixtures for ServiceModuleBoundaryRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Reader\Fixtures {
    use App\Service\Search\SearchTerms;

    final class ReaderSearches
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Recommendation\Run\Fixtures {
    final class RecommendationReads
    {
        public function extractor(): string
        {
            return \App\Service\Reader\ArticleExtractor::class;
        }
    }
}

namespace App\Service\Reading\Fixtures {
    use App\Service\Search\SearchTerms;

    final class ReadingMaySearch
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\ReaderAudit\Fixtures {
    use App\Service\Search\SearchTerms;

    final class TheAuditIsNotTheReader
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Recommendation\Feed\Fixtures {
    use App\Service\ReaderAudit\ReaderAuditRunner;

    final class RecommendationMayNameALookalike
    {
        public function __construct(public ReaderAuditRunner $runner)
        {
        }
    }
}
