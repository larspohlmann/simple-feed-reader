<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;

/** Before the user types: what the composer answers or shares, whether it may send, as whom, where text starts. */
final readonly class ComposerContextModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param list<SocialAccount> $accounts the user's connected accounts on the post's network
     * @param SocialAccount       $account  the one writing, among $accounts
     * @param bool                $canSend  whether the target's author lets the user reply or quote, as asked
     * @param PostPreviewModel|LinkCardContent $target the post replied to or quoted, or the page a link post shares
     * @param string      $text     where the text starts before the user types
     */
    public function __construct(
        public array $accounts,
        public SocialAccount $account,
        public bool $canSend,
        public ComposerDefaultsModel $defaults,
        public PostPreviewModel|LinkCardContent $target,
        public ComposerKind $kind,
        public string $text,
    ) {
    }
}
