<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LinkCardContent;
use PHPUnit\Framework\TestCase;

final class LinkCardContentTest extends TestCase
{
    public function testAStoredCardKeepsEveryField(): void
    {
        $card = new LinkCardContent(
            'https://n.example/s',
            'Story',
            'What happened.',
            'https://n.example/s.jpg',
            'News',
        );

        self::assertSame(
            [
                'url' => 'https://n.example/s',
                'title' => 'Story',
                'description' => 'What happened.',
                'imageUrl' => 'https://n.example/s.jpg',
                'siteName' => 'News',
            ],
            $card->toStored(),
        );
    }
}
