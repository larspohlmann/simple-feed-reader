<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Bluesky\Model\JsonNodeModel;

final readonly class CollectedItemsModel
{
    public const int MAX_ITEMS = 5000;

    /** @param list<JsonNodeModel> $items */
    private function __construct(
        public array $items,
        public bool $truncated,
    ) {
    }

    /** @param list<JsonNodeModel> $items everything paged in, which may run past MAX_ITEMS */
    public static function capped(array $items): self
    {
        return new self(\array_slice($items, 0, self::MAX_ITEMS), \count($items) > self::MAX_ITEMS);
    }
}
