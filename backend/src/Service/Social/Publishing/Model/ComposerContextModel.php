<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Service\Social\Social\Model\PostPreviewModel;

/** Before the user types: what the composer is about, whether it may send, as whom, where text starts. */
final readonly class ComposerContextModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param list<SocialAccount> $accounts the user's connected accounts on the post's network
     * @param SocialAccount       $account  the one writing, among $accounts
     * @param bool                $canSend  whether the network lets the user send this kind
     * @param PostPreviewModel|LinkCardContent|null $subject the post answered or quoted, the page shared, or nothing
     * @param string      $text     where the text starts before the user types
     */
    public function __construct(
        public array $accounts,
        public SocialAccount $account,
        public bool $canSend,
        public ComposerDefaultsModel $defaults,
        public PostPreviewModel|LinkCardContent|null $subject,
        public ComposerKind $kind,
        public string $text,
    ) {
    }
}
