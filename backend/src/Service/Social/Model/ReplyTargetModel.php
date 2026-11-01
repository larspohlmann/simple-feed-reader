<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\Entry;

/** The post a reply answers: the entry's own post, or a reply in its thread, as one chosen account sees it. */
final readonly class ReplyTargetModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string|null $address   a reply in the entry's thread; null answers the entry's own post
     * @param int|null    $accountId the account to answer as; null picks the one that acts on the entry
     */
    public function __construct(
        public Entry $entry,
        public ?string $address = null,
        public ?int $accountId = null,
    ) {
    }
}
