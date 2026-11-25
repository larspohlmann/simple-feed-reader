<?php

declare(strict_types=1);

namespace App\Dto\Opml;

use App\Service\Library\Opml\Model\OpmlPlacementChoiceModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpmlPreviewRequest
{
    /** @param array<int|string, string> $folderNames */
    public function __construct(
        public string $opml = '',
        #[Assert\Length(max: 100)]
        public ?string $parentTagName = null,
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(normalizer: 'trim'),
            new Assert\Length(max: 100, normalizer: 'trim'),
        ])]
        public array $folderNames = [],
    ) {
    }

    public function toChoice(): OpmlPlacementChoiceModel
    {
        return new OpmlPlacementChoiceModel($this->parentTagName, array_map('trim', $this->folderNames));
    }
}
