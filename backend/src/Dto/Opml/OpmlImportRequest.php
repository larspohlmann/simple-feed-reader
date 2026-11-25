<?php

declare(strict_types=1);

namespace App\Dto\Opml;

use App\Service\Library\Opml\Model\OpmlImportSelectionModel;
use App\Service\Library\Opml\Model\OpmlPlacementChoiceModel;
use App\Service\Library\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpmlImportRequest
{
    /**
     * @param list<string>              $feedUrls
     * @param array<string, list<int>> $tagIdsByFeedUrl
     * @param array<int|string, string> $folderNames
     */
    public function __construct(
        public string $opml = '',
        #[Assert\Length(max: 100)]
        public ?string $parentTagName = null,
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $feedUrls = [],
        #[Assert\All([new Assert\Type('array'), new Assert\All([new Assert\Type('integer'), new Assert\Positive()])])]
        public array $tagIdsByFeedUrl = [],
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(normalizer: 'trim'),
            new Assert\Length(max: 100, normalizer: 'trim'),
        ])]
        public array $folderNames = [],
    ) {
    }

    public function toSelection(): OpmlImportSelectionModel
    {
        return new OpmlImportSelectionModel(
            $this->opml,
            new OpmlPlacementChoiceModel($this->parentTagName, array_map('trim', $this->folderNames)),
            $this->feedUrls,
            $this->tagIdsByFeedUrl,
        );
    }
}
