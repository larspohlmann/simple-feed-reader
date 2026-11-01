<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\Entry;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\ReplyTargetModel;
use App\Service\Social\Support\MastodonVisibility;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReplyRequest
{
    public function __construct(
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

    public function toDraft(): ReplyDraftModel
    {
        return new ReplyDraftModel(
            $this->text,
            $this->visibility,
            $this->contentWarning,
            $this->language,
            $this->idempotencyKey,
        );
    }

    /** @param string|null $address a reply in the entry's thread; null answers the entry's own post */
    public function toTarget(Entry $entry, ?string $address): ReplyTargetModel
    {
        return new ReplyTargetModel($entry, $address, $this->accountId);
    }
}
