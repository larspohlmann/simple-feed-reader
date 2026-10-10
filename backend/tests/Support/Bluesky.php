<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use PHPUnit\Framework\Assert;

final class Bluesky
{
    public const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts';
    public const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';

    public static function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/Bluesky/' . $name . '.json');
        Assert::assertIsString($contents);

        return $contents;
    }

    public static function post(string $name): JsonNodeModel
    {
        $answer = json_decode(self::fixture($name), true, flags: \JSON_THROW_ON_ERROR);
        $posts = JsonNodeModel::of($answer)->nodes('posts');
        Assert::assertCount(1, $posts);

        return $posts[0];
    }

    private function __construct()
    {
    }
}
