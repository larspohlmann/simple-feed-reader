<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Comments\Support;

use App\Service\Content\Comments\Support\LemmyAuthor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyAuthorTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string, ?string}> */
    public static function authors(): iterable
    {
        yield 'a person' => ['Ann', 'https://Lemmy.Example/u/Ann', '@Ann@Lemmy.Example', 'ann@lemmy.example'];
        yield 'a port' => [
            'ann',
            'https://lemmy.example:8443/u/ann',
            '@ann@lemmy.example:8443',
            'ann@lemmy.example:8443',
        ];
        yield 'no name' => [null, 'https://lemmy.example/u/ann', null, null];
        yield 'no actor id' => ['ann', null, null, null];
        yield 'an actor id without a host' => ['ann', '/u/ann', null, null];
    }

    #[DataProvider('authors')]
    public function testHandleKeepsTheCaseAndIdentityLowersIt(
        ?string $name,
        ?string $actorId,
        ?string $handle,
        ?string $identity,
    ): void {
        self::assertSame($handle, LemmyAuthor::handle($name, $actorId));
        self::assertSame($identity, LemmyAuthor::identity($name, $actorId));
    }
}
