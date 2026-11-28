<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Entity\InclusionChange;
use App\Enum\FeedLanguage;
use App\Service\Library\Subscription\Model\FeedLanguageChangeModel;
use App\Service\Library\Subscription\Model\SubscriptionChangeModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateSubscriptionRequest
{
    public const string AUTOMATIC_LANGUAGE = 'automatic';

    /** @param list<int> $tagIds */
    public function __construct(
        #[Assert\Length(max: 512)]
        public ?string $customTitle = null,
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $tagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
        public ?bool $includeInSavedSearches = null,
        public ?bool $includeInTrending = null,
        public ?bool $includeInYourStream = null,
        #[Assert\Choice(callback: 'languageChoices')]
        public ?string $language = null,
    ) {
    }

    /** @return list<string> */
    public static function languageChoices(): array
    {
        return [...FeedLanguage::values(), self::AUTOMATIC_LANGUAGE];
    }

    public function toChange(): SubscriptionChangeModel
    {
        return new SubscriptionChangeModel(
            customTitle: $this->customTitle,
            tagIds: $this->tagIds,
            inclusions: new InclusionChange(
                allItems: $this->includeInAllItems,
                forYou: $this->includeInForYou,
                savedSearches: $this->includeInSavedSearches,
                trending: $this->includeInTrending,
                yourStream: $this->includeInYourStream,
            ),
            language: $this->languageChange(),
        );
    }

    private function languageChange(): ?FeedLanguageChangeModel
    {
        if (null === $this->language) {
            return null;
        }

        return new FeedLanguageChangeModel(FeedLanguage::tryFrom($this->language));
    }
}
