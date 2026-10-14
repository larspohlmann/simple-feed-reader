<?php

declare(strict_types=1);

namespace App\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Bluesky\Support\BlueskyRichText;
use App\Service\LinkCard\Support\TrailingUrl;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Share\Model\SharedLinksModel;
use App\Service\Share\Support\SharedLinks;
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
            return $this->fillFromText($entry, $post);
        }

        $body = self::sharedBody($entry->getContentHtml() ?? '', $embed);
        $this->linkCards->append($entry, [], $embed->linkCards);
        $entry->setContentHtml($this->sanitizer->sanitize($body->linkedHtml . $embed->html));
        $entry->setSummary(EntrySnippet::from($body->linkedHtml));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::storedImage($entry), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        $cardedUrls = array_map(static fn (LinkCardContent $card): string => $card->url, $embed->linkCards);

        $outcome = $body->uncardedBy($cardedUrls) === [] ? FillOutcome::Filled : FillOutcome::AwaitingLinkCards;
        if ($embed->quote !== null) {
            $entry->shareQuotedPost($embed->quote);

            return $outcome;
        }
        $entry->setShareKind(self::shareKindOf($embed, $outcome, $assembled->media));

        return $outcome;
    }

    private function fillFromText(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        $shared = SharedLinks::of(self::textBodyOf($entry, $post));
        $pages = array_filter($shared->urls, static fn (string $url): bool => !AtPostUri::isWebUrl($url));
        if ($pages === []) {
            return FillOutcome::Unfilled;
        }

        $entry->setContentHtml($this->sanitizer->sanitize($shared->linkedHtml));
        $entry->setSummary(EntrySnippet::from($shared->linkedHtml));
        $entry->setShareKind(ShareKind::Link);

        return FillOutcome::AwaitingLinkCards;
    }

    /** An RSS body shows a link shortened; the post's own facets know where it goes. */
    private static function textBodyOf(Entry $entry, JsonNodeModel $post): string
    {
        $body = $entry->getContentHtml() ?? '';
        $record = $post->node('record');
        if (!BlueskyRichText::linksAWebPage($record->nodes('facets'))) {
            return $body;
        }

        return BlueskyRichText::asHtml($record->text('text') ?? '', $record->nodes('facets')) ?? $body;
    }

    /** @param list<EntryMedium> $media */
    private static function shareKindOf(RenderedEmbedModel $embed, FillOutcome $outcome, array $media): ?ShareKind
    {
        if ($embed->linkCards !== [] || $outcome === FillOutcome::AwaitingLinkCards) {
            return ShareKind::Link;
        }

        return $media === [] ? null : ShareKind::Media;
    }

    /** The body without the URLs its cards replace, every other link it shares linked, the uncarded one included. */
    private static function sharedBody(string $html, RenderedEmbedModel $embed): SharedLinksModel
    {
        foreach ($embed->linkCards as $card) {
            $html = TrailingUrl::removedFrom($html, $card->url);
        }
        $shared = SharedLinks::of($html);
        $uncarded = $embed->uncardedLink;
        if ($uncarded === null || \in_array($uncarded, $shared->urls, true)) {
            return $shared;
        }
        $escaped = HtmlEscape::text($uncarded);

        return new SharedLinksModel(
            [...$shared->urls, $uncarded],
            $shared->linkedHtml . sprintf('<p><a href="%s">%s</a></p>', $escaped, $escaped),
            $shared->ownProse,
        );
    }

    private static function storedImage(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }
}
