<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ImportCandidatesModel
{
    /**
     * @param list<ImportCandidateModel>     $follows
     * @param list<CollectionCandidateModel> $lists
     * @param list<CollectionCandidateModel> $customFeeds
     * @param list<CollectionCandidateModel> $tags
     */
    public function __construct(
        public array $follows,
        public array $lists,
        public array $customFeeds,
        public array $tags,
        public bool $truncated,
    ) {
    }
}
