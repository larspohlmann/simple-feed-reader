<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\BlueskyActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyActorTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function actors(): iterable
    {
        yield 'a handle' => ['alice.bsky.social', true];
        yield 'a custom-domain handle' => ['motherjones.com', true];
        yield 'a plc DID' => ['did:plc:qobvnkudcv3zlaklxxjduqoi', true];
        yield 'a web DID' => ['did:web:example.com', true];
        yield 'a name with a space' => ['some name', false];
        yield 'an underscore' => ['foo_bar.bsky.social', false];
        yield 'a single label' => ['localhost', false];
        yield 'a top-level label starting with a digit' => ['alice.123', false];
        yield 'a DID ending in a colon' => ['did:plc:', false];
        yield 'a handle of the longest length' => [str_repeat(str_repeat('a', 62) . '.', 4) . 'c', true];
        yield 'a handle too long' => [str_repeat(str_repeat('a', 60) . '.', 5) . 'com', false];
    }

    #[DataProvider('actors')]
    public function testOnlyAHandleOrADidIsAnActor(string $actor, bool $valid): void
    {
        self::assertSame($valid, BlueskyActor::isValid($actor));
    }
}
