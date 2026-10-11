<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Entry;
use App\Entity\Share;
use App\Enum\ShareKind;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Parser\Support\VisualMedium;
use App\Service\Reader\Media\Support\YouTubeShortUrl;
use App\Service\Share\Support\OwnProse;
use App\Service\Share\Support\TitledShareTarget;

/**
 * Whether an entry is a shared link or a shared picture or video, so it takes the matching card. Only a short entry
 * shares: more of its own text than a Mastodon post holds makes it an article. The body's shape never decides media,
 * since a news teaser looks the same; the target does.
 */
final readonly class EntryShareClassifier
{
    public const int OWN_PROSE_MAX = 500;

    public function classify(Entry $entry): void
    {
        $body = $entry->getContentHtml() ?? $entry->getSummary() ?? '';
        $ownProse = OwnProse::of($body);
        $share = $this->shareOf($entry, $body, $ownProse);
        $entry->setShare($share);
        if ($share->isNone() || $entry->isTitleDerived()) {
            return;
        }

        $entry->setSummary($ownProse === '' ? null : mb_substr($ownProse, 0, self::OWN_PROSE_MAX));
        $medium = $share->kind === ShareKind::Media && $share->url !== null ? VisualMedium::at($share->url) : null;
        if ($medium !== null) {
            $entry->putMediumFirst($medium);
        }
    }

    private function shareOf(Entry $entry, string $body, string $ownProse): Share
    {
        if (YouTubeShortUrl::is($entry->getUrl())) {
            return Share::media(null);
        }
        if (mb_strlen($ownProse) > self::OWN_PROSE_MAX) {
            return Share::none();
        }

        return $entry->isTitleDerived() ? $this->postShare($entry, $body) : $this->titledShare($entry);
    }

    private function postShare(Entry $entry, string $body): Share
    {
        if (SharedLinks::of($body)->urls !== []) {
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
}
