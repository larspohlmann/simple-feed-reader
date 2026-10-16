<?php

declare(strict_types=1);

namespace App\Service\Comments\Pass;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\CommentsSource\CommentsSourceInterface;

final readonly class CommentsRequest
{
    private string $cacheKey;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public CommentsSourceInterface $source,
        public User $user,
        public Entry $entry,
    ) {
        $this->cacheKey = $source::class . ' ' . $source->audienceOf($user, $entry);
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }
}
