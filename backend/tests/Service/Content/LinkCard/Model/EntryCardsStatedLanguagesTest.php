<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard\Model;

use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Enum\FeedLanguage;
use App\Service\Content\LinkCard\Model\EntryCardsModel;
use App\Service\Content\LinkCard\Model\PageCardModel;
use PHPUnit\Framework\TestCase;

final class EntryCardsStatedLanguagesTest extends TestCase
{
    public function testOnlyCardsWhosePageStatesALanguageAreListed(): void
    {
        $cards = new EntryCardsModel([], [
            new PageCardModel(self::card('https://a.example/'), null, statedLanguage: FeedLanguage::French),
            new PageCardModel(self::card('https://b.example/'), null),
            new PageCardModel(
                null,
                new EntryMedium('https://c.example/i.jpg', 'image'),
                statedLanguage: FeedLanguage::German,
            ),
            new PageCardModel(self::card('https://d.example/'), null, statedLanguage: FeedLanguage::Spanish),
        ]);

        self::assertSame(
            ['https://a.example/' => FeedLanguage::French, 'https://d.example/' => FeedLanguage::Spanish],
            $cards->statedLanguagesByUrl(),
        );
    }

    private static function card(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Card', null, null, 'example');
    }
}
