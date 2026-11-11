<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateFeedDirectoryScopeRequest
{
    private const int MAX_ENTRIES = 300;

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public function __construct(
        #[Assert\Count(max: self::MAX_ENTRIES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[A-Z]{2}$/')])]
        public array $countries,
        #[Assert\Count(max: self::MAX_ENTRIES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-z]{2,3}$/')])]
        public array $languages,
    ) {
    }

    public function toScope(): FeedDirectoryScopeModel
    {
        return new FeedDirectoryScopeModel($this->countries, $this->languages);
    }
}
