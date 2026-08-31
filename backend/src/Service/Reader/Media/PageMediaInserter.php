<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Puts media the extracted body never had at the top of it.
 *
 * These candidates come from the source page, where position is not knowable —
 * and on the pages that need this most (a public-radio article that extracts to
 * a duration line and three teaser links) the media IS the article, so the top
 * is where it belongs. The existing prose is kept below, untouched.
 */
final readonly class PageMediaInserter
{
    public function __construct(private MediaMarkup $markup)
    {
    }

    public function insertInto(HTMLDocument $body, ArticleMedia $media): void
    {
        $root = $body->body;
        if ($root === null || $media->isEmpty()) {
            return;
        }

        foreach (array_reverse($media->candidates) as $candidate) {
            $root->insertBefore($this->element($body, $candidate), $root->firstChild);
        }
    }

    private function element(HTMLDocument $body, MediaCandidate $candidate): Element
    {
        return match ($candidate->kind) {
            MediaKind::Audio => $this->player($body, 'audio', $candidate),
            MediaKind::Video => $this->player($body, 'video', $candidate),
            MediaKind::Embed => $this->markup->embedLink(
                $body,
                new EmbedTarget($candidate->url, $candidate->posterUrl, $candidate->label ?? 'Open the media'),
            ),
        };
    }

    private function player(HTMLDocument $body, string $tag, MediaCandidate $candidate): Element
    {
        $player = $body->createElement($tag);
        $player->setAttribute('controls', '');
        // Never fetch megabytes for an article the reader may only be skimming.
        $player->setAttribute('preload', 'none');
        $player->setAttribute('src', $candidate->url);
        if ($candidate->posterUrl !== null) {
            $player->setAttribute('poster', $candidate->posterUrl);
        }

        return $player;
    }
}
