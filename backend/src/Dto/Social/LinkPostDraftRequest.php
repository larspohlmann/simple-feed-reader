<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Social\Model\PostDraftModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LinkPostDraftRequest
{
    public function __construct(
        #[Assert\Length(max: 10000)]
        public string $text,
        #[Assert\Choice(choices: PostDraftModel::VISIBILITIES)]
        public ?string $visibility = null,
        #[Assert\Length(max: 500)]
        public ?string $contentWarning = null,
        #[Assert\Regex('/^[a-z]{2,3}$/')]
        public ?string $language = null,
        #[Assert\Uuid]
        public ?string $idempotencyKey = null,
        #[Assert\Positive]
        public ?int $accountId = null,
        public ?string $title = null,
        #[Assert\Positive]
        public ?int $communityId = null,
    ) {
    }

    public function toDraft(): PostDraftModel
    {
        return new PostDraftModel(
            $this->text,
            $this->visibility,
            $this->contentWarning,
            $this->language,
            $this->idempotencyKey,
            $this->title,
            $this->communityId,
        );
    }
}
