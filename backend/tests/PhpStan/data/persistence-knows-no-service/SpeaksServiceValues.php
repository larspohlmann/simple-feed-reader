<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Repository\Fixtures {
    use App\Service\Search\Model\SearchTermsModel;
    use App\Service\Search\Support\LikePattern;
    use App\Service\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
    use App\Service\Reader\Exception\ArticleNotExtractedException;
    use App\Service\Url\UrlNormalizer;
    use App\Service\Backup\Dto\EntryLine;

    final class SpeaksServiceValues
    {
        public function __construct(public SearchTermsModel $terms, public UrlNormalizer $normalizer)
        {
        }

        public function line(): string
        {
            return EntryLine::class;
        }
    }
}
