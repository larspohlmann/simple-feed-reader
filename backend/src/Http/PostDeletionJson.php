<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\PostDeletion;

final class PostDeletionJson
{
    /** @return array{outcome: 'deleted'|'alreadyGone'} */
    public static function of(PostDeletion $deletion): array
    {
        return ['outcome' => $deletion->value];
    }
}
