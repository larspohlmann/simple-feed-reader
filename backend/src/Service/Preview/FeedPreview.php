<?php

declare(strict_types=1);

namespace App\Service\Preview;

final readonly class FeedPreview
{
    /**
     * @param string|null                   $title
     * @param int                           $itemCount
     * @param 'full'|'summary'|'title-only' $content
     * @param bool                          $hasImages
     * @param list<FeedPreviewItem>         $items
     */
    public function __construct(
        public ?string $title,
        public int $itemCount,
        public string $content,
        public bool $hasImages,
        public array $items,
    ) {
    }
}
