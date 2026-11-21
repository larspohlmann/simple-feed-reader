<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** What the head of a story card shows: its title and synopsis, its sources, whether it is followed, its lifespan. */
final readonly class StoryFaceModel
{
    public function __construct(
        public int $storyId,
        public StoryHeadingModel $heading,
        public StorySourcesModel $sources,
        public bool $followed,
        public StoryLifespanModel $lifespan,
    ) {
    }
}
