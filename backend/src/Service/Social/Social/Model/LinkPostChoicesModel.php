<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

/** What a link post chooses from: the communities it may go to, the title it starts with and the title's length limits. */
final readonly class LinkPostChoicesModel
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
