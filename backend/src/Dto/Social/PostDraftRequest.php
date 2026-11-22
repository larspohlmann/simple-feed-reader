<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\Entry;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\ResponseTargetModel;
use Symfony\Component\Validator\Constraints as Assert;

final class PostDraftRequest
{
    use PostDraftFields;

    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 10000)]
        public readonly string $text,
    ) {
    }

    public function toPublication(): PublicationModel
    {
        return new PublicationModel($this->buildDraft($this->text));
    }

    /** @param string|null $address a reply in the entry's thread; null answers the entry's own post */
    public function toTarget(Entry $entry, ?string $address): ResponseTargetModel
    {
        return new ResponseTargetModel($entry, $address, $this->accountId);
    }
}
