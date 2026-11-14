<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Bluesky\Model\JsonNodeModel;

final readonly class PlacedSelfReplyModel
{
    public function __construct(public string $rootGuid, public int $position, public JsonNodeModel $post)
    {
    }
}
