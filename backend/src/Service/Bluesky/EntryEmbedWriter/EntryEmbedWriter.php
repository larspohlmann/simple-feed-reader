<?php

declare(strict_types=1);

namespace App\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\Share;
use App\Repository\LinkCardRepository;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Bluesky\PostEmbedRenderer;
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
            return FillOutcome::Unfilled;
        }

        $body = self::sharedBody($entry->getContentHtml() ?? '', $embed);
        $this->linkCards->add($entry, 0, $embed->linkCards);
        $entry->setContentHtml($this->sanitizer->sanitize($body->linkedHtml . $embed->html));
        $entry->setSummary(EntrySnippet::from($body->linkedHtml));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::storedImage($entry), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        $cardedUrls = array_map(static fn (LinkCardContent $card): string => $card->url, $embed->linkCards);

        $outcome = $body->uncardedBy($cardedUrls) === [] ? FillOutcome::Filled : FillOutcome::AwaitingLinkCards;
        $entry->setShare(self::shareOf($embed, $outcome, $assembled->media));

        return $outcome;
    }

    /** @param list<EntryMedium> $media */
    private static function shareOf(RenderedEmbedModel $embed, FillOutcome $outcome, array $media): Share
    {
        if ($embed->linkCards !== [] || $outcome === FillOutcome::AwaitingLinkCards) {
            return Share::link(null);
        }

        return $media === [] ? Share::none() : Share::media(null);
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
