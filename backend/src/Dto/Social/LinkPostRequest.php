<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\LinkCardContent;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\LinkPostModel;
use App\Service\Social\Model\LinkTargetModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Support\MastodonVisibility;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LinkPostRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: LinkCardContent::URL_MAX)]
        public string $url,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 10000)]
        public string $text,
        #[Assert\Choice(choices: MastodonVisibility::ALL)]
        public ?string $visibility = null,
        #[Assert\Length(max: 500)]
        public ?string $contentWarning = null,
        #[Assert\Regex('/^[a-z]{2,3}$/')]
        public ?string $language = null,
        #[Assert\Uuid]
        public ?string $idempotencyKey = null,
        #[Assert\Positive]
        public ?int $accountId = null,
    ) {
    }

    public function toLinkPost(SocialNetwork $network): LinkPostModel
    {
        return new LinkPostModel(new LinkTargetModel($network, $this->accountId, $this->url), new PostDraftModel(
            $this->text,
            $this->visibility,
            $this->contentWarning,
            $this->language,
            $this->idempotencyKey,
        ));
    }
}
