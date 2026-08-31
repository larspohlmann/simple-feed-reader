<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * Deutschlandradio publishes the episode as the first `data-audio-src` on the
 * page. The ones after it are related teasers, and the live station stream is
 * not this episode — so only the first durable URL is taken.
 */
final readonly class DeutschlandradioAudioSource implements MediaCandidateSourceInterface
{
    private const array HOSTS = [
        'www.deutschlandfunk.de', 'deutschlandfunk.de',
        'www.deutschlandfunkkultur.de', 'deutschlandfunkkultur.de',
        'www.deutschlandfunknova.de', 'deutschlandfunknova.de',
    ];

    public function __construct(private DurableMediaUrl $durable)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        if (!$this->isDeutschlandradio($pageUrl)) {
            return [];
        }

        $episode = $this->firstDurableAudio($pageHtml);

        return $episode === null ? [] : [new MediaCandidate(MediaKind::Audio, $episode)];
    }

    private function isDeutschlandradio(string $pageUrl): bool
    {
        $host = parse_url($pageUrl, \PHP_URL_HOST);

        return \is_string($host) && \in_array(strtolower($host), self::HOSTS, true);
    }

    private function firstDurableAudio(string $pageHtml): ?string
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return null;
        }

        foreach ($document->querySelectorAll('[data-audio-src]') as $element) {
            $source = $element->getAttribute('data-audio-src') ?? '';
            if ($this->durable->accepts($source)) {
                return $source;
            }
        }

        return null;
    }
}
