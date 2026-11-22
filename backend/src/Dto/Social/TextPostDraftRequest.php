<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\TextPostTargetModel;
use Symfony\Component\Validator\Constraints as Assert;

final class TextPostDraftRequest
{
    use PostDraftFields;

    public function __construct(
        public readonly SocialNetwork $network,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 10000)]
        public readonly string $text,
    ) {
    }

    public function toTarget(): TextPostTargetModel
    {
        return new TextPostTargetModel($this->network, $this->accountId);
    }

    public function toPublication(): PublicationModel
    {
        return new PublicationModel($this->buildDraft($this->text));
    }
}
