<?php

declare(strict_types=1);

namespace App\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Repository\LinkCardRepository;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\Support\TrailingUrl;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\Support\HtmlEscape;

final readonly class EntryEmbedWriter implements EntryEmbedWriterInterface
{
    public function __construct(
        private PostEmbedRenderer $renderer,
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
        private LinkCardRepository $linkCards,
    ) {
    }

    public function fill(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        $embed = $this->renderer->render($post);
        if ($embed === null) {
            return FillOutcome::Unfilled;
        }

        $body = $entry->getContentHtml() ?? '';
        foreach ($embed->linkCards as $card) {
            $body = TrailingUrl::removedFrom($body, $card->url);
        }
        $body = self::sharing($body, $embed->uncardedLinks);
        $this->linkCards->add($entry, $embed->linkCards);
        $entry->setContentHtml($this->sanitizer->sanitize($body . $embed->html));
        $entry->setSummary(EntrySnippet::from($body));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::storedImage($entry), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        return $embed->uncardedLinks === [] ? FillOutcome::Filled : FillOutcome::AwaitingLinkCards;
    }

    /**
     * The body as the link-card pass reads it: every link it must card shared as a link.
     *
     * @param list<string> $urls
     */
    private static function sharing(string $body, array $urls): string
    {
        if ($urls === []) {
            return $body;
        }
        $shared = SharedLinks::of($body);
        $appended = '';
        foreach (array_diff($urls, $shared->urls) as $url) {
            $escaped = HtmlEscape::text($url);
            $appended .= sprintf('<p><a href="%s">%s</a></p>', $escaped, $escaped);
        }

        return $shared->linkedHtml . $appended;
    }

    private static function storedImage(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }
}
