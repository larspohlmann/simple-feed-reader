<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Model;

use App\Service\Social\Lemmy\Model\LemmySessionModel;
use PHPUnit\Framework\TestCase;

final class LemmySessionModelTest extends TestCase
{
    public function testRequestsCarryTheBearerToken(): void
    {
        $session = new LemmySessionModel('https://lemmy.example', 'test-jwt-not-a-real-token');

        $get = $session->get('/api/v3/site');
        $post = $session->postJson('/api/v3/post/like', ['post_id' => 42, 'score' => 1]);

        self::assertSame('https://lemmy.example/api/v3/site', $get->url);
        self::assertSame('Bearer test-jwt-not-a-real-token', $get->headers['Authorization']);
        self::assertSame('POST', $post->method);
        self::assertSame('https://lemmy.example/api/v3/post/like', $post->url);
        self::assertSame(['post_id' => 42, 'score' => 1], $post->json);
        self::assertSame('Bearer test-jwt-not-a-real-token', $post->headers['Authorization']);
    }
}
