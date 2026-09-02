<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\RedirectFollower;

/**
 * A stream is fetched by script, not by the media element, so it plays only from
 * the URL that finally serves it: a cross-origin fetch dies on a redirect hop
 * without a CORS header (zdfheute.de answers its playlist URL with a bare 301 to
 * Akamai). Each Stream candidate is followed to where it lands; a chain that
 * fails, or lands anywhere but on a durable playlist, keeps the declared URL —
 * the native client follows redirects on its own.
 */
final readonly class StreamLocationResolver
{
    private const int MAX_REDIRECTS = 5;
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private RedirectFollower $redirects,
        private MediaUrlKind $mediaUrlKind,
        private string $userAgent,
    ) {
    }

    public function resolve(ArticleMedia $media): ArticleMedia
    {
        return new ArticleMedia(array_map($this->located(...), $media->candidates));
    }

    private function located(MediaCandidate $candidate): MediaCandidate
    {
        if ($candidate->kind !== MediaKind::Stream) {
            return $candidate;
        }
        $landing = $this->mediaUrlKind->resolve($this->landingUrlOf($candidate->url));

        return $landing?->kind === MediaKind::Stream ? $candidate->at($landing->url) : $candidate;
    }

    private function landingUrlOf(string $url): string
    {
        try {
            $landed = $this->redirects->follow($url, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException) {
            return $url;
        }
        $landed->response->cancel();

        return $landed->isSuccess() ? $landed->url : $url;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
        ];
    }
}
