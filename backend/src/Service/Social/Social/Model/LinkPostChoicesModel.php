<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

/** What a Lemmy link post chooses from: the communities it may go to and the title it starts with. */
final readonly class LinkPostChoicesModel
{
    public const int MIN_TITLE_CHARACTERS = 3;
    public const int MAX_TITLE_CHARACTERS = 200;

    /** @param list<CommunityModel> $communities */
    public function __construct(public array $communities, public string $title)
    {
    }
}
