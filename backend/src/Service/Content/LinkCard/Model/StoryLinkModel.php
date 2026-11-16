<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

final readonly class StoryLinkModel
{
    public function __construct(
        public string $urlHash,
        public string $title,
        public ?string $description,
        public string $siteName,
    ) {
    }

    public function summary(): string
    {
        $headline = trim($this->siteName) === '' ? $this->title : $this->title . ' (' . $this->siteName . ')';

        return $this->description === null || trim($this->description) === ''
            ? $headline
            : $headline . ': ' . $this->description;
    }
}
