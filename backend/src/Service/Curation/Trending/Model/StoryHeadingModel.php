<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** What the story-title call writes for one story: its title, and a synopsis when the reply gave a usable one. */
final readonly class StoryHeadingModel
{
    public function __construct(public string $title, public ?string $synopsis)
    {
    }
}
