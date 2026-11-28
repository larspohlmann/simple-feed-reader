<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Enum\FeedLanguage;
use PHPUnit\Framework\TestCase;

final class UpdateSubscriptionRequestTest extends TestCase
{
    public function testToChangeCarriesTheTitleTheTagsAndEveryFlag(): void
    {
        $change = (new UpdateSubscriptionRequest('Mine', [7, 8], false, true, false, false, false))->toChange();

        self::assertSame('Mine', $change->customTitle);
        self::assertSame([7, 8], $change->tagIds);
        self::assertSame(
            [
                'allItems' => false,
                'forYou' => true,
                'savedSearches' => false,
                'trending' => false,
                'yourStream' => false,
            ],
            get_object_vars($change->inclusions),
        );
    }

    public function testANamedLanguageBecomesThatLanguage(): void
    {
        $change = (new UpdateSubscriptionRequest(language: 'es'))->toChange();

        self::assertSame(FeedLanguage::Spanish, $change->language?->language);
    }

    public function testAutomaticBecomesAChangeToNoLanguage(): void
    {
        $change = (new UpdateSubscriptionRequest(language: 'automatic'))->toChange();

        self::assertNotNull($change->language);
        self::assertNull($change->language->language);
    }

    public function testNoLanguageMeansNoLanguageChange(): void
    {
        self::assertNull((new UpdateSubscriptionRequest())->toChange()->language);
    }
}
