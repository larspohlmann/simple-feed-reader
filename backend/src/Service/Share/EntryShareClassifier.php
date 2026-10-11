<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Entry;
use App\Entity\Share;
use App\Enum\ShareKind;
use App\Service\Parser\Support\VisualMedium;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Share\Model\SharedLinksModel;
use App\Service\Share\PendingEmbed\PendingEmbedInterface;
use App\Service\Share\Support\SharedLinks;
use App\Service\Share\Support\TitledShareTarget;
use App\Service\Url\Support\YouTubeUrl;

/**
 * Whether an entry is a shared link or a shared picture or video, so it takes the matching card. Only a short entry
 * shares: more of its own text than a Mastodon post holds makes it an article. The body's shape never decides media,
 * since a news teaser looks the same; the target does.
 */
final readonly class EntryShareClassifier
{
    public const int OWN_PROSE_MAX = 500;

    public function __construct(
        private PendingEmbedInterface $pendingEmbed,
        private EntrySanitizer $sanitizer,
    ) {
    }

    public function classify(Entry $entry): void
    {
        $body = SharedLinks::of($entry->getContentHtml() ?? $entry->getSummary() ?? '');
        $share = $this->orProvisionalLink($entry, $this->shareOf($entry, $body));
        $entry->setShare($share);
        if ($share->isNone()) {
            return;
        }

        if ($entry->isTitleDerived()) {
            $this->linkBareUrls($entry, $body);

            return;
        }
        $this->describeTitledShare($entry, $share, $body->ownProse);
    }

    private function shareOf(Entry $entry, SharedLinksModel $body): Share
    {
        if (YouTubeUrl::isShort($entry->getUrl())) {
            return Share::media(null);
        }
        if (mb_strlen($body->ownProse) > self::OWN_PROSE_MAX) {
            return Share::none();
        }

        return $entry->isTitleDerived() ? $this->postShare($entry, $body) : $this->titledShare($entry);
    }

    private function orProvisionalLink(Entry $entry, Share $share): Share
    {
        return $share->isNone() && $this->pendingEmbed->awaitsEmbed($entry) ? Share::link(null) : $share;
    }

    private function postShare(Entry $entry, SharedLinksModel $body): Share
    {
        if ($body->urls !== []) {
            return Share::link(null);
        }

        return $entry->getMedia() === [] ? Share::none() : Share::media(null);
    }

    private function titledShare(Entry $entry): Share
    {
        $target = TitledShareTarget::of($entry);
        if ($target === null) {
            return Share::none();
        }

        return VisualMedium::at($target) === null ? Share::link($target) : Share::media($target);
    }

    private function linkBareUrls(Entry $entry, SharedLinksModel $body): void
    {
        $contentHtml = $entry->getContentHtml();
        if ($contentHtml === null || $body->linkedHtml === $contentHtml || $this->pendingEmbed->awaitsEmbed($entry)) {
            return;
        }

        $entry->setContentHtml($this->sanitizer->sanitize($body->linkedHtml));
    }

    private function describeTitledShare(Entry $entry, Share $share, string $ownProse): void
    {
        $entry->setSummary($ownProse === '' ? null : mb_substr($ownProse, 0, self::OWN_PROSE_MAX));
        $medium = $share->kind === ShareKind::Media && $share->url !== null ? VisualMedium::at($share->url) : null;
        if ($medium !== null) {
            $entry->putMediumFirst($medium);
        }
    }
}
