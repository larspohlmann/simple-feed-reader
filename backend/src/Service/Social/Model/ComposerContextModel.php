<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;

/** What the composer needs before the user types: who may answer, as whom, and where the text starts. */
final readonly class ComposerContextModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param list<SocialAccount> $accounts the user's connected accounts on the post's network
     * @param SocialAccount       $account  the one answering, among $accounts
     */
    public function __construct(
        public array $accounts,
        public SocialAccount $account,
        public bool $canReply,
        public ComposerDefaultsModel $defaults,
    ) {
    }
}
