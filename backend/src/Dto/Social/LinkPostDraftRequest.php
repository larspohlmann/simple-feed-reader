<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\LinkCardContent;
use App\Enum\SocialNetwork;
use App\Service\Social\Publishing\Model\LemmyCommunityPostModel;
use App\Service\Social\Publishing\Model\LinkTargetModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use Symfony\Component\Validator\Constraints as Assert;

final class LinkPostDraftRequest
{
    use PostDraftFields;

    public function __construct(
        public readonly SocialNetwork $network,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: LinkCardContent::URL_MAX)]
        public readonly string $url,
        #[Assert\Length(max: 10000)]
        public readonly string $text,
        public readonly ?string $title = null,
        #[Assert\Positive]
        public readonly ?int $communityId = null,
    ) {
    }

    public function toLinkTarget(): LinkTargetModel
    {
        return new LinkTargetModel($this->network, $this->accountId, $this->url);
    }

    public function toPublication(): PublicationModel
    {
        $publication = new PublicationModel($this->buildDraft($this->text));
        if (null === $this->title && null === $this->communityId) {
            return $publication;
        }

        return $publication->withLemmy(new LemmyCommunityPostModel($this->communityId, $this->title));
    }
}
