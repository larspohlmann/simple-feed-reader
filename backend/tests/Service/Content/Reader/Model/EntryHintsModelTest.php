<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Model;

use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\FeedMediaModel;
use PHPUnit\Framework\TestCase;

final class EntryHintsModelTest extends TestCase
{
    public function testNoHintsMeanNoTitleNoAuthorAndNoFeedMedia(): void
    {
        $hints = new EntryHintsModel();

        self::assertNull($hints->title);
        self::assertNull($hints->author);
        self::assertNull($hints->feedMedia->posterFallback());
    }

    public function testKeepsTheFeedMediaItIsGiven(): void
    {
        $feedMedia = FeedMediaModel::none();

        self::assertSame($feedMedia, (new EntryHintsModel(feedMedia: $feedMedia))->feedMedia);
    }
}
