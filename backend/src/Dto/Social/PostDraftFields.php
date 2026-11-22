<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Publishing\Model\PostDraftModel;
use Symfony\Component\Validator\Constraints as Assert;

trait PostDraftFields
{
    #[Assert\Choice(choices: PostDraftModel::VISIBILITIES)]
    public ?string $visibility = null;

    #[Assert\Length(max: 500)]
    public ?string $contentWarning = null;

    #[Assert\Regex('/^[a-z]{2,3}$/')]
    public ?string $language = null;

    #[Assert\Uuid]
    public ?string $idempotencyKey = null;

    #[Assert\Positive]
    public ?int $accountId = null;

    private function buildDraft(string $text): PostDraftModel
    {
        return new PostDraftModel(
            $text,
            $this->visibility,
            $this->contentWarning,
            $this->language,
            $this->idempotencyKey,
        );
    }
}
