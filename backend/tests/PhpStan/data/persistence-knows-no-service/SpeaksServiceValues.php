<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Repository\Fixtures {
    use App\Service\Library\Search\Model\SearchTermsModel;
    use App\Service\Library\Search\Support\LikePattern;
    use App\Service\Library\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
    use App\Service\Content\Reader\Exception\ArticleNotExtractedException;
    use App\Service\Foundation\Url\UrlNormalizer;
    use App\Service\Operations\Backup\Dto\EntryLine;

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
