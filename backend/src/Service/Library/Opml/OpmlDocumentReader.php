<?php

declare(strict_types=1);

namespace App\Service\Library\Opml;

use App\Enum\SourceFormat;
use App\Service\Foundation\Url\Support\MixcloudUrl;
use App\Service\Library\Opml\Exception\InvalidOpmlException;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFolderModel;

final readonly class OpmlDocumentReader
{
    private const int MAX_BYTES = 1_048_576;
    private const int MAX_TITLE_LENGTH = 512;

    public function __construct(private OpmlBodyReader $bodyReader)
    {
    }

    public function read(string $opml): OpmlFolderModel
    {
        if ($opml === '' || \strlen($opml) > self::MAX_BYTES) {
            throw new InvalidOpmlException('The OPML body is empty or larger than 1 MB.');
        }

        $seenUrls = [];

        return $this->readFolder(null, $this->bodyReader->read($opml), $seenUrls);
    }

    /** @param array<string, true> $seenUrls */
    private function readFolder(?string $name, \DOMElement $node, array &$seenUrls): OpmlFolderModel
    {
        $feeds = [];
        $folders = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
                continue;
            }

            $xmlUrl = trim($child->getAttribute('xmlUrl'));
            if ($xmlUrl !== '') {
                if (!isset($seenUrls[$xmlUrl])) {
                    $seenUrls[$xmlUrl] = true;
                    $feeds[] = new OpmlFeedModel(
                        $xmlUrl,
                        $this->readTitle($child),
                        $this->resolveSourceFormat($child, $xmlUrl),
                        $this->readSiteUrl($child),
                    );
                }
                continue;
            }

            $folder = $this->readFolder($this->readName($child), $child, $seenUrls);
            if ($folder->name !== null) {
                $folders[] = $folder;
                continue;
            }
            array_push($feeds, ...$folder->feeds);
            array_push($folders, ...$folder->folders);
        }

        return new OpmlFolderModel($name, $feeds, $folders);
    }

    private function readTitle(\DOMElement $outline): ?string
    {
        $title = $this->readName($outline);

        return $title === null ? null : mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    private function readSiteUrl(\DOMElement $outline): ?string
    {
        $siteUrl = trim($outline->getAttribute('htmlUrl'));
        $parts = parse_url($siteUrl);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        return \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ? $siteUrl : null;
    }

    private function readName(\DOMElement $outline): ?string
    {
        $name = trim($outline->getAttribute('text'));
        if ($name === '') {
            $name = trim($outline->getAttribute('title'));
        }

        return $name === '' ? null : $name;
    }

    private function resolveSourceFormat(\DOMElement $outline, string $xmlUrl): string
    {
        if (!$outline->hasAttribute('sourceFormat')) {
            return MixcloudUrl::isCloudcastsUrl($xmlUrl) ? SourceFormat::MIXCLOUD_API : SourceFormat::XML;
        }
        $declared = trim($outline->getAttribute('sourceFormat'));

        return SourceFormat::isVerbatim($declared) ? $declared : SourceFormat::XML;
    }
}
