<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model\PostEmbed;

use App\Service\Social\Social\Model\SocialPostModel;

final readonly class QuotedPostEmbedModel implements PostEmbedModelInterface
{
    public function __construct(public SocialPostModel $post)
    {
    }
}
