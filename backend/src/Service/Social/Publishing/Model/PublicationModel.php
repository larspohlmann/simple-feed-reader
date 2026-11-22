<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

use App\Service\Social\Publishing\Model\PostEmbed\PostEmbedModelInterface;
use App\Service\Social\Social\Model\SocialPostModel;

/** A draft and what it is attached to: the post it answers, what it embeds, where on Lemmy it goes. */
final readonly class PublicationModel
{
    public function __construct(
        public PostDraftModel $draft,
        public ?SocialPostModel $inReplyTo = null,
        public ?PostEmbedModelInterface $embed = null,
        public ?LemmyCommunityPostModel $lemmy = null,
    ) {
    }

    #[\NoDiscard]
    public function withReplyTo(SocialPostModel $post): self
    {
        return clone($this, ['inReplyTo' => $post]);
    }

    #[\NoDiscard]
    public function withEmbed(PostEmbedModelInterface $embed): self
    {
        return clone($this, ['embed' => $embed]);
    }

    #[\NoDiscard]
    public function withLemmy(LemmyCommunityPostModel $lemmy): self
    {
        return clone($this, ['lemmy' => $lemmy]);
    }
}
