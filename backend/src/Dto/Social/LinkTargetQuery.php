<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\LinkCardContent;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\LinkTargetModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LinkTargetQuery
{
    public function __construct(
        public SocialNetwork $network,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: LinkCardContent::URL_MAX)]
        public string $url,
        public string $title = '',
    ) {
    }

    public function toLinkTarget(?int $accountId): LinkTargetModel
    {
        return new LinkTargetModel(
            $this->network,
            $accountId,
            $this->url,
            mb_substr($this->title, 0, LinkCardContent::TITLE_MAX),
        );
    }
}
