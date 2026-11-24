<?php

declare(strict_types=1);

namespace App\Dto\Opml;

use App\Service\Library\Opml\Model\OpmlImportSelectionModel;
use App\Service\Library\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpmlImportRequest
{
    /**
     * @param list<string>              $feedUrls
     * @param array<string, list<int>> $tagIdsByFeedUrl
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $opml = '',
        #[Assert\Length(max: 100)]
        public ?string $parentTagName = null,
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $feedUrls = [],
        #[Assert\All([new Assert\Type('array'), new Assert\All([new Assert\Type('integer'), new Assert\Positive()])])]
        public array $tagIdsByFeedUrl = [],
    ) {
    }

    public function toSelection(): OpmlImportSelectionModel
    {
        return new OpmlImportSelectionModel($this->opml, $this->parentTagName, $this->feedUrls, $this->tagIdsByFeedUrl);
    }
}
