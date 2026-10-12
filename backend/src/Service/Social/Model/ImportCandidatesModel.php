<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ImportCandidatesModel
{
    /**
     * @param list<ImportCandidateModel>     $follows
     * @param list<CollectionCandidateModel> $lists
     * @param list<CollectionCandidateModel> $customFeeds
     */
    public function __construct(
        public array $follows,
        public array $lists,
        public array $customFeeds,
        public bool $truncated,
    ) {
    }
}
