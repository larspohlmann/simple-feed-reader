<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * NPR's segment audio. The URL carries playback analytics (`t=progseg`,
 * `sc=siteplayer`, `aw_0_1st.playerid`) that read like a signature and are not:
 * the bare path returns an identical 200 audio/mpeg. Stripping the query is
 * therefore safe and is what makes the URL durable enough to embed.
 */
final readonly class NprAudioSource implements MediaCandidateSourceInterface
{
    private const array HOSTS = ['www.npr.org', 'npr.org', 'text.npr.org'];

    /** The anonymous, tokenless delivery path — the host segment says so. */
    private const string AUDIO_PATTERN = '#https://ondemand\.npr\.org/anon\.npr-mp3/[^"\'\s\\\\]+?\.mp3#i';

    public function __construct(private DurableMediaUrl $durable)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        if (!$this->isNpr($pageUrl)) {
            return [];
        }

        preg_match_all(self::AUDIO_PATTERN, $pageHtml, $matches);

        return $this->firstDurable($matches[0]);
    }

    private function isNpr(string $pageUrl): bool
    {
        $host = parse_url($pageUrl, \PHP_URL_HOST);

        return \is_string($host) && \in_array(strtolower($host), self::HOSTS, true);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<MediaCandidate>
     */
    private function firstDurable(array $urls): array
    {
        foreach ($urls as $url) {
            if ($this->durable->accepts($url)) {
                return [new MediaCandidate(MediaKind::Audio, $url)];
            }
        }

        return [];
    }
}
