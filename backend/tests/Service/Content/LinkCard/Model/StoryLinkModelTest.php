<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard\Model;

use App\Service\Content\LinkCard\Model\StoryLinkModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoryLinkModelTest extends TestCase
{
    public function testTheSummaryNamesTitleSiteAndDescription(): void
    {
        $story = new StoryLinkModel('hash', 'Title', 'About it', 'Example');

        self::assertSame('Title (Example): About it', $story->summary());
    }

    #[DataProvider('descriptionsThatSayNothing')]
    public function testTheSummaryDropsADescriptionThatSaysNothing(?string $description): void
    {
        self::assertSame('Title (Example)', (new StoryLinkModel('hash', 'Title', $description, 'Example'))->summary());
    }

    /** @return iterable<string, array{?string}> */
    public static function descriptionsThatSayNothing(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'blank' => ["  \n"];
    }
}
