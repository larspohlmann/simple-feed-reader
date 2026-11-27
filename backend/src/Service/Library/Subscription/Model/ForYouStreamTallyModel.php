<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Model;

final readonly class ForYouStreamTallyModel
{
    public function __construct(
        public bool $available,
        public int $unreadCount,
        public int $entryCount,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, 0, 0);
    }
}
