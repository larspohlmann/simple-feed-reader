<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag\Factory;

use App\Entity\User;
use App\Service\Tag\Factory\TagFactory;
use App\Service\Tag\TagDetails;
use PHPUnit\Framework\TestCase;

final class TagFactoryTest extends TestCase
{
    public function testATagTakesItsDetailsAndItsPosition(): void
    {
        $user = new User('tagger@example.test', new \DateTimeImmutable('2026-08-01'));

        $tag = (new TagFactory())->create($user, new TagDetails('Science', '#123456', 'flask'), 7);

        self::assertSame($user, $tag->getUser());
        self::assertSame('Science', $tag->getName());
        self::assertSame('#123456', $tag->getColor());
        self::assertSame('flask', $tag->getIcon());
        self::assertSame(7, $tag->getPosition());
    }
}
