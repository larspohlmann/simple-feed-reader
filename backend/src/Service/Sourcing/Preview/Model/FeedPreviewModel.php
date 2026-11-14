<?php

declare(strict_types=1);

namespace App\Service\Preview\Model;

final readonly class FeedPreviewModel
{
    /**
     * @param 'full'|'summary'|'title-only' $content
     * @param list<FeedPreviewItemModel>    $items
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
