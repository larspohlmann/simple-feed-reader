<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Publishing\Model\LemmyCommunityPostModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use Symfony\Component\Validator\Constraints as Assert;

final class LinkPostDraftRequest
{
    use PostDraftFields;

    public function __construct(
        #[Assert\Length(max: 10000)]
        public readonly string $text,
        public readonly ?string $title = null,
        #[Assert\Positive]
        public readonly ?int $communityId = null,
    ) {
    }

    public function toPublication(): PublicationModel
    {
        $publication = PublicationModel::fromDraft($this->buildDraft($this->text));
        if (null === $this->title && null === $this->communityId) {
            return $publication;
        }

        return $publication->withLemmy(new LemmyCommunityPostModel($this->communityId, $this->title));
    }
}
