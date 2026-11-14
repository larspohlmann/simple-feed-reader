<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Pass;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Content\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Content\Comments\Model\CommentsAudienceModel;

final readonly class CommentsRequest
{
    public CommentsAudienceModel $audience;

    public string $cacheKey;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public CommentsSourceInterface $source,
        public User $user,
        public Entry $entry,
    ) {
        $this->audience = $source->audienceOf($user, $entry);
        $this->cacheKey = $source::class . ' ' . $this->audience->scope;
    }
}
