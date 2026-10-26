<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\EmbedDetailsJson;
use App\Service\Chapter\EmbedDetails;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Url\Support\YouTubeUrl;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class EmbedDetailsController
{
    public function __construct(
        private EmbedDetails $embedDetails,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $embedDetailsLimiter,
    ) {
    }

    #[Route(
        '/api/youtube/videos/{videoId}',
        name: 'api_youtube_video_details',
        methods: ['GET'],
        requirements: ['videoId' => YouTubeUrl::VIDEO_ID_PATTERN],
    )]
    public function video(string $videoId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->embedDetailsLimiter, $user);
        $details = $this->embedDetails->of(new EmbedAddressModel(ChapteredEmbed::YouTube, $videoId));

        return new JsonResponse(EmbedDetailsJson::of($details));
    }

    #[Route(
        '/api/soundcloud/tracks/{trackId}',
        name: 'api_soundcloud_track_details',
        methods: ['GET'],
        requirements: ['trackId' => '\d{1,20}'],
    )]
    public function track(string $trackId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->embedDetailsLimiter, $user);
        $details = $this->embedDetails->of(new EmbedAddressModel(ChapteredEmbed::SoundCloud, $trackId));

        return new JsonResponse(EmbedDetailsJson::of($details));
    }
}
