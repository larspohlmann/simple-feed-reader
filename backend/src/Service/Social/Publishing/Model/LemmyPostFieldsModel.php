<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

/** What a Lemmy post chooses from: the communities it may go to, its starting title and the title's limits. */
final readonly class LemmyPostFieldsModel
{
    /** @param list<CommunityModel> $communities */
    public function __construct(
        public array $communities,
        public string $title,
        public int $minTitleCharacters,
        public int $maxTitleCharacters,
    ) {
    }
}
