<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\EmbedChaptersJson;
use App\Service\Chapter\EmbedChapters;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Url\Support\YouTubeUrl;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class EmbedChaptersController
{
    public function __construct(
        private EmbedChapters $embedChapters,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $embedChaptersLimiter,
    ) {
    }

    #[Route(
        '/api/youtube/videos/{videoId}/chapters',
        name: 'api_youtube_video_chapters',
        methods: ['GET'],
        requirements: ['videoId' => YouTubeUrl::VIDEO_ID_PATTERN],
    )]
    public function video(string $videoId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->embedChaptersLimiter, $user);
        $chapters = $this->embedChapters->of(new EmbedAddressModel(ChapteredEmbed::YouTube, $videoId));

        return new JsonResponse(EmbedChaptersJson::of($chapters));
    }

    #[Route(
        '/api/soundcloud/tracks/{trackId}/chapters',
        name: 'api_soundcloud_track_chapters',
        methods: ['GET'],
        requirements: ['trackId' => '\d{1,20}'],
    )]
    public function track(string $trackId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->embedChaptersLimiter, $user);
        $chapters = $this->embedChapters->of(new EmbedAddressModel(ChapteredEmbed::SoundCloud, $trackId));

        return new JsonResponse(EmbedChaptersJson::of($chapters));
    }
}
