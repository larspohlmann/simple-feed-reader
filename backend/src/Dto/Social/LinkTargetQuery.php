<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\LinkCardContent;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\LinkTargetModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LinkTargetQuery
{
    /** The longest selection the composer starts from; share-content.ts clips to the same `QUOTE_MAX`. */
    public const int QUOTE_MAX = 1000;

    public function __construct(
        public SocialNetwork $network,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: LinkCardContent::URL_MAX)]
        public string $url,
        public string $title = '',
        #[Assert\Length(max: self::QUOTE_MAX)]
        public string $quote = '',
    ) {
    }

    #[Assert\IsTrue(message: 'This network takes no link posts.')]
    public function isLinkPostNetwork(): bool
    {
        return $this->network->takesLinkPosts();
    }

    public function toLinkTarget(?int $accountId): LinkTargetModel
    {
        return new LinkTargetModel(
            $this->network,
            $accountId,
            $this->url,
            mb_substr($this->title, 0, LinkCardContent::TITLE_MAX),
            $this->quote,
        );
    }
}
